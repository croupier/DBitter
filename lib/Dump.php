<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Work with dump files: names and extensions (none/gzip/zstd), streaming
 * read/write handles, compression/decompression via the external zstd binary, splitting
 * into parts and reassembly, sha256 checksums, JSON manifests (contents,
 * sizes, hashes) and the latest.json pointer, plus rotation of old dumps
 * (prune). Used by the export/import actions; file operations go
 * through Fs, zstd execution through Command::capture.
 */
final class Dump
{
    /**
     * Determines the compression algorithm from a file extension — so that import knows
     * how to read the dump, regardless of what the manifest says.
     *
     * @param string $path Path to the dump file.
     * @return string 'gzip' (.gz), 'zstd' (.zst) or 'none'.
     */
    public static function algoFromFile(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.gz') => 'gzip',
            str_ends_with($path, '.zst') => 'zstd',
            default => 'none',
        };
    }

    /**
     * Dump file extension for the selected compression algorithm.
     *
     * @param string $algo 'gzip', 'zstd' or anything else (no compression).
     * @return string '.sql.gz', '.sql.zst' or '.sql'.
     */
    public static function extension(string $algo): string
    {
        return match ($algo) {
            'gzip' => '.sql.gz',
            'zstd' => '.sql.zst',
            default => '.sql',
        };
    }

    /**
     * Builds a base dump name of the form "project__environment__kind__timestamp".
     * Each component is passed through a slug filter: any character outside
     * [A-Za-z0-9_.-] is replaced with '-', so the name is safe for any
     * filesystem and shell. The timestamp is unique (dbt_now, random suffix).
     *
     * @param string $project Project name.
     * @param string $env Environment (local/wsl/...).
     * @param string $kind Dump kind (for example, full/db).
     * @return string Base name without extension.
     */
    public static function baseName(string $project, string $env, string $kind): string
    {
        $slug = static fn(string $s): string => preg_replace('/[^A-Za-z0-9_.-]+/', '-', $s) ?? 'x';
        return sprintf('%s__%s__%s__%s', $slug($project), $slug($env), $kind, dbt_now());
    }

    /**
     * Opens a dump for writing in binary streaming mode; for gzip via
     * gzopen with level 6 (a speed/size trade-off), otherwise plain
     * fopen. The directory is created when needed.
     *
     * Side effects: creates the directory and the dump file.
     *
     * @param string $path Dump file path.
     * @param string $algo Algorithm: 'gzip' or other (no compression).
     * @return resource Handle for fwrite/gzwrite.
     * @throws \RuntimeException If the file cannot be opened for writing.
     */
    public static function writeHandle(string $path, string $algo)
    {
        Fs::ensureDir(dirname($path));
        $handle = $algo === 'gzip' ? gzopen($path, 'wb6') : fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open for write: {$path}");
        }
        return $handle;
    }

    /**
     * Opens a dump for reading: gzip via gzopen (transparent decompression
     * while reading), anything else with fopen. Binary mode is mandatory on Windows
     * so that the 0x0A/0x1A bytes are not mangled.
     *
     * @param string $path Dump file path.
     * @param string $algo Algorithm: 'gzip' or other (no compression).
     * @return resource Handle for fread/gzread.
     * @throws \RuntimeException If the file cannot be opened for reading.
     */
    public static function readHandle(string $path, string $algo)
    {
        $handle = $algo === 'gzip' ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open for read: {$path}");
        }
        return $handle;
    }

    /**
     * Compresses a finished .sql file with the external zstd (PHP has no built-in
     * zstd support). Flags: -q quiet, -f overwrite, --rm remove the source after
     * successful compression, -o — the result name. The default level is 19,
     * i.e. maximum compression at the cost of time (dumps are kept for a long time).
     *
     * Side effects: creates the file "<path>.zst" and deletes the original .sql.
     *
     * @param string $plainPath Path to the uncompressed .sql.
     * @param int $level zstd compression level (1..19+).
     * @return string Path to the created .zst file.
     * @throws \RuntimeException If the zstd binary is not found or compression failed.
     */
    public static function compressZstd(string $plainPath, int $level = 19): string
    {
        $bin = Fs::which('zstd');
        if ($bin === null) {
            throw new \RuntimeException('zstd binary not found; use --compress=gzip or none');
        }
        $dest = $plainPath . '.zst';
        $res = Command::capture([$bin, "-{$level}", '-q', '-f', '--rm', '-o', $dest, $plainPath]);
        if (!$res->ok()) {
            throw new \RuntimeException('zstd compress failed: ' . $res->output());
        }
        return $dest;
    }

    /**
     * Decompresses a .zst into a temporary .sql file with a random name
     * (import reads it as a stream; parallel imports will not collide).
     * The source .zst is NOT deleted — the caller removes the temporary file itself.
     *
     * Side effects: creates a temporary file in Fs::tempDir().
     *
     * @param string $zstPath Path to the compressed dump.
     * @return string Path to the decompressed temporary .sql.
     * @throws \RuntimeException If zstd is not found or decompression failed.
     */
    public static function decompressZstd(string $zstPath): string
    {
        $bin = Fs::which('zstd');
        if ($bin === null) {
            throw new \RuntimeException('zstd binary not found; cannot decompress');
        }
        $dest = rtrim(Fs::tempDir(), '/\\') . DIRECTORY_SEPARATOR . 'dbt_zstd_' . bin2hex(random_bytes(4)) . '.sql';
        $res = Command::capture([$bin, '-d', '-q', '-f', '-o', $dest, $zstPath]);
        if (!$res->ok()) {
            throw new \RuntimeException('zstd decompress failed: ' . $res->output());
        }
        return $dest;
    }

    /**
     * Cuts a file into parts of partBytes bytes each: "<path>.part001", ".part002"...
     * Needed to transfer dumps past size limits (email, file sharing).
     * Copying proceeds in 8 KB chunks — memory does not grow with dump size.
     *
     * Side effects: creates the part files and DELETES the source file
     * (except when no splitting was performed).
     *
     * @param string $path Path to the source file.
     * @param int $partBytes Maximum part size in bytes; <=0 means don't split.
     * @return list<string> Part paths in order (or [the original path]).
     * @throws \RuntimeException If the source file or a part cannot be opened.
     */
    public static function splitFile(string $path, int $partBytes): array
    {
        if ($partBytes <= 0) {
            return [$path];
        }
        $size = filesize($path);
        if ($size !== false && $size <= $partBytes) {
            // Файл целиком помещается в один блок — оставляем исходный
            // единый архив и не плодим избыточный .part001: манифест и
            // импорт тогда работают с обычным однофайловым дампом.
            return [$path];
        }
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Cannot read for split: {$path}");
        }
        $parts = [];
        $index = 0;
        while (!feof($in)) {
            $partPath = sprintf('%s.part%03d', $path, $index + 1);
            $out = fopen($partPath, 'wb');
            $written = 0;
            // Читаем не больше остатка до лимита части, чтобы не превысить
            // partBytes даже последним куском.
            while ($written < $partBytes && !feof($in)) {
                $chunk = fread($in, min(8192, $partBytes - $written));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
                $written += strlen($chunk);
            }
            fclose($out);
            // Если размер файла кратен partBytes, цикл зайдёт ещё раз и
            // создаст пустую часть (0 байт прочитано). Такой пустой хвост
            // удаляем: он ломал бы склейку/проверки и просто бесполезен.
            if ($written === 0) {
                unlink($partPath);
                break;
            }
            $index++;
            $parts[] = $partPath;
            // Неполная часть означает, что вход исчерпан — дальше резать нечего.
            if ($written < $partBytes) {
                break;
            }
        }
        fclose($in);
        // Исходник удаляем только после успешной нарезки: остаются лишь части.
        unlink($path);
        return $parts;
    }

    /**
     * Concatenates the parts (in the given order) into a single file — the inverse operation
     * of splitFile during import. Streaming copy in 8 KB chunks.
     *
     * Side effects: creates/overwrites the file dest; the parts are not deleted.
     *
     * @param list<string> $parts Part paths in order (.part001, .part002...).
     * @param string $dest Path to the resulting file.
     * @throws \RuntimeException If dest cannot be opened for writing or a part for reading.
     */
    public static function joinFiles(array $parts, string $dest): void
    {
        $out = fopen($dest, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Cannot write join dest: {$dest}");
        }
        foreach ($parts as $part) {
            $in = fopen($part, 'rb');
            if ($in === false) {
                throw new \RuntimeException("Cannot read part: {$part}");
            }
            while (!feof($in)) {
                $chunk = fread($in, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
            fclose($in);
        }
        fclose($out);
    }

    /**
     * sha256 of a file — the checksum used in the manifest and for integrity
     * verification on import (hash_file reads as a stream, so memory does not grow).
     *
     * @param string $path File path.
     * @return string Hex sha256 string (64 characters).
     * @throws \RuntimeException If the file is unreadable / the hash could not be computed.
     */
    public static function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException("Cannot hash: {$path}");
        }
        return $hash;
    }

    /**
     * Writes the dump manifest as JSON (file inventory, sizes, hashes,
     * database parameters). The JSON_* flags give a readable layout without escaping
     * slashes or Cyrillic; the directory is created when needed.
     *
     * Side effects: creates/overwrites the manifest file and its directory.
     *
     * @param string $path Path to the JSON manifest.
     * @param array<string,mixed> $data Manifest data.
     */
    public static function writeManifest(string $path, array $data): void
    {
        Fs::ensureDir(dirname($path));
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    /**
     * Reads a manifest; JSON_THROW_ON_ERROR turns broken JSON into an
     * exception, so that import does not continue with empty or wrong data.
     *
     * @param string $path Path to the JSON manifest.
     * @return array<string,mixed> Parsed manifest data.
     * @throws \RuntimeException If the file is unreadable.
     * @throws \JsonException If the JSON is malformed.
     */
    public static function readManifest(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read manifest: {$path}");
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Updates the latest.json pointer in the dump directory: the name of the most
     * recent manifest and the write time. Import without parameters uses it to find
     * the freshest dump.
     *
     * Side effects: creates/overwrites <dir>/latest.json.
     *
     * @param string $dir Dump directory.
     * @param string $manifestName Manifest file name (basename).
     */
    public static function writeLatest(string $dir, string $manifestName): void
    {
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'latest.json',
            json_encode(['manifest' => $manifestName, 'at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Reads the pointer to the latest dump; a missing file is not an error
     * (no dumps have been made yet), null is returned.
     *
     * @param string $dir Dump directory.
     * @return array<string,mixed>|null latest.json data or null.
     */
    public static function readLatest(string $dir): ?array
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'latest.json';
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    /**
     * Dump rotation: deletes manifests (together with their files) that
     * exceed the count limit keep (the newest ones are preserved) OR
     * are older than olderDays days (if an age limit is set). latest.json
     * is always excluded from consideration.
     *
     * Side effects: irreversibly deletes dump files and JSON manifests.
     *
     * @param string $dir Dump directory.
     * @param int $keep How many of the most recent dumps to keep.
     * @param int $olderDays Delete dumps older than N days; 0 disables the age limit.
     * @return int Number of deleted manifests (dumps).
     */
    public static function prune(string $dir, int $keep, int $olderDays = 0): int
    {
        $manifests = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        // latest.json — служебный указатель, а не манифест дампа: не удаляем.
        $manifests = array_values(array_filter($manifests, static fn(string $p): bool => basename($p) !== 'latest.json'));
        // Сортировка от новых к старым: индексы 0..keep-1 — защищённые дампы.
        usort($manifests, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $removed = 0;
        $now = time();
        foreach ($manifests as $i => $manifest) {
            $ageOk = $olderDays > 0 && ($now - (int) filemtime($manifest)) > $olderDays * 86400;
            $overKeep = $i >= $keep;
            if ($overKeep || $ageOk) {
                $data = json_decode((string) file_get_contents($manifest), true) ?: [];
                foreach ($data['files'] ?? [] as $entry) {
                    // entries — это массивы ['file' => имя, 'bytes' => ..,
                    // 'sha256' => ..]. Раньше здесь была ошибка Array-to-string:
                    // entry пытались использовать как строку напрямую. Берём
                    // поле 'file', а для старых манифестов с голой строкой
                    // сохраняем совместимость (is_array-ветвление).
                    $name = is_array($entry) ? (string) ($entry['file'] ?? '') : (string) $entry;
                    if ($name === '') {
                        continue;
                    }
                    $fp = $dir . DIRECTORY_SEPARATOR . $name;
                    if (is_file($fp)) {
                        unlink($fp);
                    }
                }
                unlink($manifest);
                $removed++;
            }
        }
        return $removed;
    }
}
