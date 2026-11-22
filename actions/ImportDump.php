<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Действие import: потоковая подача дампа в stdin клиента mysql.
 *
 * Запуск: php start.php import [--file=дамп|манифест|часть] [--database=имя]
 * [--fk-checks=yes|no] [--force]. Без --file берётся свежий дамп по latest.json.
 * Проверяет sha256 по манифесту (если не задан --force), склеивает части,
 * распаковывает gzip/zstd и отключает FOREIGN_KEY_CHECKS на время импорта.
 */

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Dump;
use DbTools\Lib\Fs;
use DbTools\Lib\Log;
use DbTools\Lib\Menu;
use DbTools\Lib\Process;

/**
 * Импорт дампа в MySQL: находит файл (или манифест/части), верифицирует
 * контрольные суммы и потоком пишет SQL в stdin mysql через транспорт.
 */
final class ImportDump extends Action
{
    /**
     * Машинное имя действия для CLI.
     *
     * @return string всегда 'import'
     */
    public function name(): string
    {
        return 'import';
    }

    /**
     * Заголовок для интерактивного меню/справки.
     *
     * @return string описание действия
     */
    public function title(): string
    {
        return 'Import dump (file → mysql)';
    }

    /**
     * CLI-опции импорта для справки/меню.
     *
     * @return array<string,string> имя опции => описание
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
     * Основной сценарий импорта: разрешение файла дампа, проверка sha256,
     * подготовка (распаковка zstd), потоковая подача SQL в mysql stdin
     * с обрамлением SET FOREIGN_KEY_CHECKS/UNIQUE_CHECKS.
     *
     * Побочные эффекты: изменяет целевую БД (выполняет SQL дампа), создаёт
     * временные файлы (склейка частей, распаковка zstd) и удаляет их в конце;
     * пишет в лог. При обрыве пайпа показывает ошибку сервера, а не «успех».
     *
     * @param Config $cfg  конфигурация проекта/окружения и опции импорта
     * @param array  $opts опции CLI (значения читаются через $cfg->opt)
     * @return int 0 — успех (или dry-run), 1 — ошибка файла/суммы/импорта
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
     * Находит дамп для импорта: явный --file (абсолютный или относительно
     * dumpDir), манифест .json или обычный файл; без --file — свежий дамп
     * через latest.json. Пополняет $cleanup временными файлами склейки.
     *
     * Побочные эффекты: может создать временный файл склейки частей
     * (через fromManifest); читает latest.json и манифест с диска.
     *
     * @param Config       $cfg     конфигурация с опцией file
     * @param string       $dir     каталог дампов
     * @param list<string> $cleanup список временных файлов для последующей уборки (по ссылке)
     * @return array{0:string,1:string,2:?array} [путь к файлу, алгоритм сжатия, манифест|null]
     * @throws \RuntimeException если файл не найден, latest.json отсутствует или манифест пуст
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
     * Разворачивает манифест в конкретный файл для чтения: один файл — как есть,
     * несколько частей — склеивает во временный файл (порядок частей сохраняется,
     * т.к. split резал единый сжатый поток и склейка восстанавливает его побайтово).
     *
     * Побочные эффекты: при нескольких частях создаёт временный файл во
     * Fs::tempDir() и добавляет его путь в $cleanup для удаления после импорта.
     *
     * @param array        $manifest разобранный JSON-манифест дампа
     * @param string       $dir      каталог дампов (где лежат части)
     * @param list<string> $cleanup  список временных файлов для уборки (по ссылке)
     * @return array{0:string,1:string,2:array} [путь к файлу, алгоритм сжатия, манифест]
     * @throws \RuntimeException если манифест не содержит файлов или файл отсутствует на диске
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
