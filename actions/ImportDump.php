<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * import action: streaming a dump into the mysql client's stdin.
 *
 * Run: php start.php import [--file=dump|manifest|part] [--database=name]
 * [--fk-checks=yes|no] [--force]. Without --file the latest dump is taken via latest.json.
 * Verifies sha256 against the manifest (unless --force is given), joins the parts,
 * decompresses gzip/zstd and disables FOREIGN_KEY_CHECKS for the duration of the import.
 */

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Dump;
use DbTools\Lib\Fs;
use DbTools\Lib\Log;
use DbTools\Lib\Menu;
use DbTools\Lib\Process;

/**
 * Dump import into MySQL: locates the file (or manifest/parts), verifies the
 * checksums and streams the SQL into the mysql stdin through the transport.
 */
final class ImportDump extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'import'
     */
    public function name(): string
    {
        return 'import';
    }

    /**
     * Title for the interactive menu/help.
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Import dump (file → mysql)';
    }

    /**
     * Import CLI options for help/menu.
     *
     * @return array<string,string> option name => description
     */
    public function options(): array
    {
        return [
            'file' => 'dump file / manifest / part (default: latest)',
            'database' => 'override target database name',
            'fk-checks' => 'disable foreign key checks during import (default: yes)',
            'force' => 'skip sha256 verification',
        ];
    }

    /**
     * Main import scenario: resolving the dump file, sha256 verification,
     * preparation (zstd decompression), streaming the SQL into the mysql stdin
     * wrapped in SET FOREIGN_KEY_CHECKS/UNIQUE_CHECKS statements.
     *
     * Side effects: modifies the target database (runs the dump's SQL), creates
     * temporary files (part joining, zstd decompression) and deletes them at the end;
     * writes to the log. On a broken pipe it shows the server error instead of "success".
     *
     * @param Config $cfg  project/environment configuration and import options
     * @param array  $opts CLI options (values are read via $cfg->opt)
     * @return int 0 — success (or dry-run), 1 — file/checksum/import error
     */
    public function run(Config $cfg, array $opts): int
    {
        $db = $cfg->db();
        // v1 поддерживает только MySQL/MariaDB — как и экспорт.
        if ($db['engine'] !== 'mysql') {
            return $this->fail('v1 supports MySQL/MariaDB only; engine=' . $db['engine']);
        }
        $t = $this->transport($cfg);
        $dir = $cfg->dumpDir();
        // --database позволяет импортировать дамп в БД с другим именем.
        $targetDb = (string) $cfg->opt('database', $db['name']);
        // Список временных файлов (склейка частей, распакованный zstd),
        // которые нужно удалить после импорта при любом исходе.
        $cleanup = [];

        try {
            [$dumpPath, $algo, $manifest] = $this->resolveDump($cfg, $dir, $cleanup);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // Верификация целостности по sha256 из манифеста: битый или подменённый
        // дамп не должен попасть в БД. --force пропускает проверку сознательно.
        // hash_equals — сравнение за константное время (без тайминг-утечек).
        if (empty($cfg->cli()['force']) && $manifest !== null) {
            foreach ($manifest['files'] as $entry) {
                $fp = $dir . DIRECTORY_SEPARATOR . $entry['file'];
                if (is_file($fp) && !hash_equals($entry['sha256'], Dump::sha256($fp))) {
                    return $this->fail('Checksum mismatch: ' . $entry['file'] . ' (use --force to skip)');
                }
            }
            Log::debug('Checksums verified.');
        }

        $argv = array_merge($this->clientBase($cfg), ['--database=' . $targetDb]);
        $env = $this->pwdEnv($cfg);

        // --dry-run: показываем команду и источник stdin, ничего не выполняем.
        if ($this->dryRun($cfg, $argv, $env)) {
            Log::info('[dry-run] stdin <- ' . $dumpPath . ' (' . $algo . ')');
            return 0;
        }

        // fk-checks=true (по умолчанию) означает ОТКЛЮЧИТЬ проверки внешних
        // ключей на время импорта: дамп может создавать таблицы в порядке,
        // не совпадающем с зависимостями FK, и без этого импорт бы падал.
        $fk = (bool) $cfg->opt('fk-checks', true);
        Log::step(sprintf('Importing %s into %s (target=%s, fk-checks=%s)', basename($dumpPath), $targetDb, $t->name(), $fk ? 'off' : 'on'));

        // zstd распаковываем заранее во временный файл (потокового чтения zstd
        // в PHP нет), gzip читается потоково прямо из исходного файла.
        if ($algo === 'zstd') {
            $plain = Dump::decompressZstd($dumpPath);
            $cleanup[] = $plain;
            $readPath = $plain;
            $readAlgo = 'none';
        } else {
            $readPath = $dumpPath;
            $readAlgo = $algo;
        }

        $in = Dump::readHandle($readPath, $readAlgo);
        $launch = $t->launch($argv, $env);
        // Команда в логе — только с замаскированными секретами.
        Log::debug('launch: ' . Command::mask(Command::display($launch['cmd']), $cfg->secrets()));
        // Режим 'write': пишем в stdin процесса mysql.
        $proc = new Process($launch['cmd'], $launch['env'], 'write');
        $stdin = $proc->stream();

        $bytes = 0;
        $stdinError = null;
        // Замыкание-«кормилец»: пишет блок в stdin и детектит обрыв пайпа.
        // Короткая запись ($written !== strlen) значит, что mysql уже завершился
        // (например, с ошибкой SQL) и закрыл stdin. Без этой проверки цикл
        // «докармливал» бы мёртвый пайп и импорт выглядел бы тихим успехом;
        // вместо этого фиксируем $stdinError и ниже показываем ошибку сервера.
        // @fwrite подавляет warning о broken pipe — обрабатываем его сами.
        $feed = static function (string $data) use ($stdin, &$stdinError): bool {
            $written = @fwrite($stdin, $data);
            if ($written !== strlen($data)) {
                $stdinError = 'mysql stdin closed early (broken pipe)';
                return false;
            }
            return true;
        };

        // Пролог: отключаем проверки FK и уникальности — ускоряет массовые
        // INSERT и снимает требования к порядку таблиц/строк в дампе.
        if ($fk) {
            $feed("SET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
        }
        // Основной поток: читаем дамп блоками по 8 КБ (gzread для gzip) и
        // подаём в mysql; при первом обрыве пайпа цикл прекращается.
        while ($stdinError === null && !feof($in)) {
            $chunk = $readAlgo === 'gzip' ? gzread($in, 8192) : fread($in, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            $feed($chunk);
        }
        // Эпилог: возвращаем проверки только если пайп ещё жив — иначе запись
        // бессмысленна и лишь перезаписала бы диагностику.
        if ($fk && $stdinError === null) {
            $feed("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        }
        $readAlgo === 'gzip' ? gzclose($in) : fclose($in);
        // finish() закрывает stdin, ждёт завершения mysql и собирает его вывод.
        $res = $proc->finish();

        // Временные файлы убираем независимо от исхода импорта.
        foreach ($cleanup as $tmp) {
            @unlink($tmp);
        }

        // Обрыв пайпа: почти всегда mysql упал на ошибке SQL — показываем
        // его сообщение (stderr/stdout), чтобы причина не осталась «за кадром».
        if ($stdinError !== null) {
            return $this->fail('Import aborted: ' . $stdinError . '; server said: ' . trim($res->output()));
        }

        if (!$res->ok()) {
            return $this->fail('mysql import failed (code ' . $res->code . '): ' . trim($res->stderr ?: $res->stdout));
        }

        return $this->ok(sprintf('Imported %s of SQL in %ss.', Fs::human($bytes), round($res->elapsed, 1)));
    }

    /**
     * Locates the dump to import: an explicit --file (absolute or relative to
     * dumpDir), a .json manifest or a plain file; without --file — the latest dump
     * via latest.json. Appends temporary join files to $cleanup.
     *
     * Side effects: may create a temporary part-joining file
     * (via fromManifest); reads latest.json and the manifest from disk.
     *
     * @param Config       $cfg     configuration with the file option
     * @param string       $dir     dump directory
     * @param list<string> $cleanup list of temporary files for later cleanup (by reference)
     * @return array{0:string,1:string,2:?array} [file path, compression algorithm, manifest|null]
     * @throws \RuntimeException when the file is not found, latest.json is missing or the manifest lists no files
     */
    private function resolveDump(Config $cfg, string $dir, array &$cleanup): array
    {
        $file = (string) $cfg->opt('file', '');
        if ($file !== '') {
            // Абсолютный путь (unix '/' или Windows 'C:') берём как есть,
            // относительный — трактуем как имя внутри каталога дампов.
            $path = str_starts_with($file, '/') || preg_match('#^[A-Za-z]:#', $file) ? $file : $dir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                throw new \RuntimeException("Dump file not found: {$path}");
            }
            // .json — это манифест: из него узнаём сжатие, части и контрольные суммы.
            if (str_ends_with($path, '.json')) {
                $manifest = Dump::readManifest($path);
                return $this->fromManifest($manifest, $dir, $cleanup);
            }
            // Обычный файл: алгоритм сжатия определяется по расширению,
            // манифеста нет — проверка sha256 будет пропущена.
            return [$path, Dump::algoFromFile($path), null];
        }

        // Без --file — берём последний экспорт: latest.json указывает на манифест.
        $latest = Dump::readLatest($dir);
        if ($latest === null) {
            throw new \RuntimeException('No latest.json in ' . $dir . '; export first or pass --file=');
        }
        $manifest = Dump::readManifest($dir . DIRECTORY_SEPARATOR . $latest['manifest']);
        return $this->fromManifest($manifest, $dir, $cleanup);
    }

    /**
     * Resolves a manifest into a concrete file to read: a single file is used as is,
     * several parts are joined into a temporary file (part order is preserved
     * because split cut a single compressed stream and joining restores it byte-for-byte).
     *
     * Side effects: with multiple parts it creates a temporary file in
     * Fs::tempDir() and adds its path to $cleanup for deletion after the import.
     *
     * @param array        $manifest parsed dump JSON manifest
     * @param string       $dir      dump directory (where the parts live)
     * @param list<string> $cleanup  list of temporary files for cleanup (by reference)
     * @return array{0:string,1:string,2:array} [file path, compression algorithm, manifest]
     * @throws \RuntimeException when the manifest lists no files or a file is missing on disk
     */
    private function fromManifest(array $manifest, string $dir, array &$cleanup): array
    {
        $algo = $manifest['compress'] ?? 'none';
        $files = $manifest['files'] ?? [];
        if ($files === []) {
            throw new \RuntimeException('Manifest lists no files.');
        }
        if (count($files) === 1) {
            $path = $dir . DIRECTORY_SEPARATOR . $files[0]['file'];
            if (!is_file($path)) {
                throw new \RuntimeException('Dump file missing: ' . $path);
            }
            return [$path, $algo, $manifest];
        }
        // Частей несколько: собираем полные пути в порядке манифеста и склеиваем.
        $parts = [];
        foreach ($files as $entry) {
            $parts[] = $dir . DIRECTORY_SEPARATOR . $entry['file'];
        }
        // Случайный суффикс имени — чтобы параллельные импорты не столкнулись
        // во временном каталоге; расширение соответствует алгоритму сжатия.
        $joined = Fs::tempDir() . DIRECTORY_SEPARATOR . 'dbt_join_' . bin2hex(random_bytes(4)) . Dump::extension($algo);
        Dump::joinFiles($parts, $joined);
        $cleanup[] = $joined;
        return [$joined, $algo, $manifest];
    }
}
