<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Работа с файлами дампов: имена и расширения (none/gzip/zstd), потоковые
 * handle чтения/записи, сжатие/распаковка zstd внешним бинарником, нарезка
 * на части и склейка, sha256-контрольные суммы, JSON-манифесты (состав,
 * размеры, хэши) и указатель latest.json, а также ротация старых дампов
 * (prune). Используется действиями export/import; файловые операции —
 * через Fs, запуск zstd — через Command::capture.
 */
final class Dump
{
    /**
     * Определяет алгоритм сжатия по расширению файла — чтобы импорт знал,
     * как читать дамп, независимо от того, что записано в манифесте.
     *
     * @param string $path Путь к файлу дампа.
     * @return string 'gzip' (.gz), 'zstd' (.zst) или 'none'.
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
     * Расширение файла дампа для выбранного алгоритма сжатия.
     *
     * @param string $algo 'gzip', 'zstd' или иное (без сжатия).
     * @return string '.sql.gz', '.sql.zst' или '.sql'.
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
     * Строит базовое имя дампа вида "проект__окружение__тип__метка-времени".
     * Каждый компонент прогоняется через slug: любые символы вне
     * [A-Za-z0-9_.-] заменяются на '-', чтобы имя было безопасно для любой
     * ФС и шелла. Метка времени уникальна (dbt_now, случайный суффикс).
     *
     * @param string $project Имя проекта.
     * @param string $env Окружение (local/wsl/...).
     * @param string $kind Тип дампа (например, full/db).
     * @return string Базовое имя без расширения.
     */
    public static function baseName(string $project, string $env, string $kind): string
    {
        $slug = static fn(string $s): string => preg_replace('/[^A-Za-z0-9_.-]+/', '-', $s) ?? 'x';
        return sprintf('%s__%s__%s__%s', $slug($project), $slug($env), $kind, dbt_now());
    }

    /**
     * Открывает дамп на запись в бинарном потоковом режиме; для gzip —
     * через gzopen с уровнем 6 (компромисс скорость/размер), иначе обычный
     * fopen. Каталог создаётся при необходимости.
     *
     * Побочные эффекты: создаёт каталог и файл дампа.
     *
     * @param string $path Путь файла дампа.
     * @param string $algo Алгоритм: 'gzip' или прочее (без сжатия).
     * @return resource Handle для fwrite/gzwrite.
     * @throws \RuntimeException Если файл не открылся на запись.
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
     * Открывает дамп на чтение: gzip — через gzopen (прозрачная распаковка
     * при чтении), прочее — fopen. Бинарный режим обязателен на Windows,
     * чтобы не искажались байты 0x0A/0x1A.
     *
     * @param string $path Путь файла дампа.
     * @param string $algo Алгоритм: 'gzip' или прочее (без сжатия).
     * @return resource Handle для fread/gzread.
     * @throws \RuntimeException Если файл не открылся на чтение.
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
     * Сжимает готовый .sql-файл внешним zstd (в PHP нет встроенной поддержки
     * zstd). Флаги: -q тихо, -f перезаписать, --rm удалить исходник после
     * успешного сжатия, -o — имя результата. Уровень по умолчанию 19 —
     * максимальное сжатие ценой времени (дампы хранятся долго).
     *
     * Побочные эффекты: создаёт файл "<path>.zst", удаляет исходный .sql.
     *
     * @param string $plainPath Путь несжатого .sql.
     * @param int $level Уровень сжатия zstd (1..19+).
     * @return string Путь созданного .zst-файла.
     * @throws \RuntimeException Если бинарник zstd не найден или сжатие упало.
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
     * Распаковывает .zst во временный .sql-файл со случайным именем
     * (импорт читает его потоково; параллельные импорты не столкнутся).
     * Исходный .zst НЕ удаляется — вызывающий сам уберёт временный файл.
     *
     * Побочные эффекты: создаёт временный файл в Fs::tempDir().
     *
     * @param string $zstPath Путь сжатого дампа.
     * @return string Путь распакованного временного .sql.
     * @throws \RuntimeException Если zstd не найден или распаковка упала.
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
     * Режет файл на части по partBytes байт: "<path>.part001", ".part002"...
     * Нужно для переноса дампов через ограничители размера (почта, файлообмен).
     * Копирование идёт кусками по 8 КБ — память не растёт с размером дампа.
     *
     * Побочные эффекты: создаёт файлы частей и УДАЛЯЕТ исходный файл
     * (кроме случаев, когда нарезка не выполнялась).
     *
     * @param string $path Путь исходного файла.
     * @param int $partBytes Максимальный размер части в байтах; <=0 — не резать.
     * @return list<string> Пути частей по порядку (или [исходный путь]).
     * @throws \RuntimeException Если исходный файл или часть не открывается.
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
     * Склеивает части (в переданном порядке) в один файл — обратная операция
     * к splitFile при импорте. Потоковое копирование по 8 КБ.
     *
     * Побочные эффекты: создаёт/перезаписывает файл dest; части не удаляются.
     *
     * @param list<string> $parts Пути частей по порядку (.part001, .part002...).
     * @param string $dest Путь результирующего файла.
     * @throws \RuntimeException Если не открыть dest на запись или часть на чтение.
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
     * sha256 файла — контрольная сумма для манифеста и проверки целостности
     * при импорте (hash_file читает потоково, память не растёт).
     *
     * @param string $path Путь файла.
     * @return string Hex-строка sha256 (64 символа).
     * @throws \RuntimeException Если файл не читается/не удалось посчитать хэш.
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
     * Записывает манифест дампа в JSON (состав файлов, размеры, хэши,
     * параметры БД). Флаги JSON_* дают читаемый вид без экранирования
     * слэшей и кириллицы; каталог создаётся при необходимости.
     *
     * Побочные эффекты: создаёт/перезаписывает файл манифеста и его каталог.
     *
     * @param string $path Путь JSON-манифеста.
     * @param array<string,mixed> $data Данные манифеста.
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
     * Читает манифест; JSON_THROW_ON_ERROR превращает битый JSON в
     * исключение, чтобы импорт не продолжился с пустыми/ложными данными.
     *
     * @param string $path Путь JSON-манифеста.
     * @return array<string,mixed> Разобранные данные манифеста.
     * @throws \RuntimeException Если файл не читается.
     * @throws \JsonException Если JSON повреждён.
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
     * Обновляет указатель latest.json в каталоге дампов: имя последнего
     * манифеста и время записи. По нему импорт без параметров находит
     * самый свежий дамп.
     *
     * Побочные эффекты: создаёт/перезаписывает <dir>/latest.json.
     *
     * @param string $dir Каталог дампов.
     * @param string $manifestName Имя файла манифеста (basename).
     */
    public static function writeLatest(string $dir, string $manifestName): void
    {
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'latest.json',
            json_encode(['manifest' => $manifestName, 'at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Читает указатель последнего дампа; отсутствие файла — не ошибка
     * (дампов ещё не было), возвращается null.
     *
     * @param string $dir Каталог дампов.
     * @return array<string,mixed>|null Данные latest.json или null.
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
     * Ротация дампов: удаляет манифесты (вместе с их файлами), которые
     * выходят за лимит количества keep (самые новые сохраняются) ИЛИ
     * старше olderDays дней (если лимит возраста задан). latest.json
     * исключается из рассмотрения всегда.
     *
     * Побочные эффекты: безвозвратно удаляет файлы дампов и JSON-манифесты.
     *
     * @param string $dir Каталог дампов.
     * @param int $keep Сколько последних дампов хранить.
     * @param int $olderDays Удалять дампы старше N дней; 0 — лимит возраста выключен.
     * @return int Количество удалённых манифестов (дампов).
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
