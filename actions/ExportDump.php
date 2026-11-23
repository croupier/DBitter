<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * export action: streaming MySQL dump via mysqldump into a file.
 *
 * Run: php start.php export [--kind=full|schema|data] [--compress=gzip|zstd|none]
 * [--split=MiB] [--tables=...] [--ignore-table=db.tbl] [--where=...] [--extra=flag].
 * The dump is written straight into a compressed stream (no intermediate .sql),
 * accompanied by a sha256 manifest and latest.json; supports splitting into
 * parts and retention cleanup of old dumps (--retention-keep/--retention-days).
 */

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Dump;
use DbTools\Lib\Fs;
use DbTools\Lib\Log;
use DbTools\Lib\Process;

/**
 * Database dump export: builds the mysqldump arguments, reads its stdout as a stream
 * and writes it to disk with compression, then produces the manifest and updates latest.json.
 */
final class ExportDump extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'export'
     */
    public function name(): string
    {
        return 'export';
    }

    /**
     * Title for the interactive menu/help.
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Export dump (mysqldump → file)';
    }

    /**
     * Export CLI options for help/menu.
     *
     * @return array<string,string> option name => description
     */
    public function options(): array
    {
        return [
            'kind' => 'full | schema | data',
            'compress' => 'gzip | zstd | none',
            'split' => 'part size in MiB (0 = single file)',
            'tables' => 'comma-separated table whitelist',
            'ignore-table' => 'repeatable: db.table to exclude',
            'where' => 'row filter for data',
            'extra' => 'repeatable: raw mysqldump flag',
        ];
    }

    /**
     * Main export scenario: launching mysqldump, streaming the file write,
     * compression/splitting, the sha256 manifest, latest.json and retention prune.
     *
     * Side effects: creates the dump/parts/manifest directory and files in dumpDir,
     * overwrites latest.json, deletes old dumps when retention applies; writes to the log.
     * The database is only read (mysqldump); on a write/dump error the file is deleted.
     *
     * @param Config $cfg  project/environment configuration and export options
     * @param array  $opts CLI options (values are read via $cfg->opt)
     * @return int 0 — success (or dry-run), 1 — engine/write/dump/compression error
     */
    public function run(Config $cfg, array $opts): int
    {
        $db = $cfg->db();
        // v1 умеет только MySQL/MariaDB: другие движки не поддерживаются mysqldump.
        if ($db['engine'] !== 'mysql') {
            return $this->fail('v1 supports MySQL/MariaDB only; engine=' . $db['engine']);
        }
        $t = $this->transport($cfg);
        $kind = (string) $cfg->opt('kind', 'full');
        $algo = (string) $cfg->opt('compress', 'gzip');
        $splitMb = (float) $cfg->opt('split', 0);

        $argv = $this->buildArgs($cfg, $kind);
        $env = $this->pwdEnv($cfg);

        // --dry-run: только показать команду, ничего не запускать и не писать.
        if ($this->dryRun($cfg, $argv, $env)) {
            return 0;
        }

        $dir = $cfg->dumpDir();
        Fs::ensureDir($dir);
        // Имя базируется на проекте/окружении/виде дампа — по нему потом prune
        // находит «свои» дампы, а манифест называется так же с расширением .json.
        $base = Dump::baseName($cfg->projectName(), $cfg->env(), $kind);
        $manifestName = $base . '.json';

        Log::step("Exporting {$db['name']} (kind={$kind}, compress={$algo}, target={$t->name()})");

        // zstd не имеет потокового API в PHP: сначала пишем НЕСЖАТЫЙ файл
        // (streamAlgo='none'), а после успешного дампа сжимаем его внешней
        // утилитой zstd. gzip же пишется потоково прямо во время дампа.
        $streamAlgo = $algo === 'zstd' ? 'none' : $algo;
        $writePath = $dir . DIRECTORY_SEPARATOR . $base . Dump::extension($streamAlgo);
        $out = Dump::writeHandle($writePath, $streamAlgo);

        $launch = $t->launch($argv, $env);
        // Команда логируется только после маскирования секретов (пароль и т.п.).
        Log::debug('launch: ' . Command::mask(Command::display($launch['cmd']), $cfg->secrets()));
        // Режим 'read': нам нужен stdout процесса mysqldump как источник данных.
        $proc = new Process($launch['cmd'], $launch['env'], 'read');
        $src = $proc->stream();

        $plainBytes = 0;
        $writeError = null;
        // Потоковая запись: читаем stdout mysqldump блоками по 8 КБ и сразу
        // пишем в gzwrite/fwrite — промежуточный .sql на диске не создаётся,
        // поэтому дамп любой величины не требует двойного места и лишнего прохода.
        while (!feof($src)) {
            $chunk = fread($src, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $len = strlen($chunk);
            $written = $algo === 'gzip' ? gzwrite($out, $chunk) : fwrite($out, $chunk);
            // Детект короткой записи: записано меньше, чем передано, — значит
            // диск полон или пайп оборвался; продолжать нельзя, дамп был бы битым.
            if ($written !== $len) {
                $writeError = 'short write (' . $written . '/' . $len . ' bytes) — disk full or broken pipe';
                break;
            }
            $plainBytes += $len;
        }
        $algo === 'gzip' ? gzclose($out) : fclose($out);
        // finish() дожидается завершения mysqldump и собирает код возврата/stderr.
        $res = $proc->finish();

        // Ошибка записи: недописанный файл удаляем, чтобы битый дамп не попал
        // в манифест и не был потом импортирован.
        if ($writeError !== null) {
            @unlink($writePath);
            return $this->fail('Export aborted: ' . $writeError);
        }

        // mysqldump вернул ошибку — файл тоже удаляем: частичный дамп бесполезен.
        if (!$res->ok()) {
            @unlink($writePath);
            return $this->fail('mysqldump failed (code ' . $res->code . '): ' . trim($res->stderr ?: $res->stdout));
        }

        $finalPath = $writePath;
        if ($algo === 'zstd') {
            // Отложенное сжатие zstd (уровень по умолчанию 19 — максимальная степень).
            try {
                $finalPath = Dump::compressZstd($writePath, (int) $cfg->opt('zstd-level', 19));
            } catch (\Throwable $e) {
                // При неудаче zstd убираем исходный несжатый файл: не оставляем
                // ни недожатых артефактов, ни мусора, о котором не знает манифест.
                @unlink($writePath);
                return $this->fail('zstd compression failed: ' . $e->getMessage());
            }
        }

        $files = [];
        if ($splitMb > 0) {
            // Разбиение на части заданного размера (например, для переноса через
            // ограниченные каналы); каждая часть получает свой размер и sha256.
            $parts = Dump::splitFile($finalPath, Fs::bytes($splitMb));
            foreach ($parts as $part) {
                $files[] = ['file' => basename($part), 'bytes' => filesize($part), 'sha256' => Dump::sha256($part)];
            }
        } else {
            $files[] = ['file' => basename($finalPath), 'bytes' => filesize($finalPath), 'sha256' => Dump::sha256($finalPath)];
        }

        $storedBytes = array_sum(array_column($files, 'bytes'));
        // Манифест — самодостаточное описание дампа: параметры, происхождение
        // (проект/окружение/git-ревизия), размеры и sha256 каждого файла.
        // Импорт по нему проверяет целостность и склеивает части.
        $manifest = [
            'tool' => 'DBitter',
            'created' => date('c'),
            'project' => $cfg->projectName(),
            'env' => $cfg->env(),
            'conf' => $db['source'] ?? null,
            'target' => $t->name(),
            'engine' => $db['engine'],
            'kind' => $kind,
            'database' => $db['name'],
            'prefix' => $db['prefix'],
            'charset' => $db['charset'],
            'compress' => $algo,
            // Опции запуска сохраняем уже маскированными: манифест — файл на диске,
            // секреты в нём оставаться не должны.
            'options' => Command::mask(implode(' ', $argv), $cfg->secrets()),
            'git' => self::gitRev(),
            'elapsed' => round($res->elapsed, 2),
            'plain_bytes' => $plainBytes,
            'stored_bytes' => $storedBytes,
            'files' => $files,
        ];
        Dump::writeManifest($dir . DIRECTORY_SEPARATOR . $manifestName, $manifest);
        // latest.json — указатель на свежий манифест: импорт без --file берёт его.
        Dump::writeLatest($dir, $manifestName);

        // Retention-prune: храним не более N последних дампов (и/или не старше
        // M дней); выполняется только после успешной записи нового дампа.
        $keep = (int) $cfg->opt('retention-keep', 0);
        if ($keep > 0) {
            $removed = Dump::prune($dir, $keep, (int) $cfg->opt('retention-days', 0));
            if ($removed > 0) {
                Log::info("Pruned {$removed} old dump(s).");
            }
        }

        // Процент экономии от сжатия: сколько места сэкономлено относительно plain.
        $ratio = $plainBytes > 0 ? round(100 * (1 - $storedBytes / $plainBytes), 1) : 0.0;
        return $this->ok(sprintf(
            'Done: %s (%s stored, %s plain, -%s%%, %ss, %d file(s))',
            $manifestName,
            Fs::human($storedBytes),
            Fs::human($plainBytes),
            $ratio,
            round($res->elapsed, 1),
            count($files)
        ));
    }

    /**
     * Builds the full mysqldump argv for the given dump kind.
     * Public and shared with show-command: the command preview must match the
     * actual run, so the build logic is not duplicated.
     *
     * @param Config $cfg  configuration and options (kind-dependent flags, where, extra)
     * @param string $kind dump kind: 'full' | 'schema' | 'data'
     * @return list<string> mysqldump arguments (no password — it lives in MYSQL_PWD)
     */
    public function buildArgs(Config $cfg, string $kind): array
    {
        $db = $cfg->db();
        $argv = $this->clientBase($cfg, true);
        // --no-tablespaces: не требует привилегии PROCESS и не тянет tablespaces;
        // --quick: построчное чтение без буферизации таблиц в памяти дампера;
        // --hex-blob: бинарные поля в hex — безопасно для любой кодировки/экранирования;
        // --set-gtid-purged=OFF: без GTID-стейтментов, чтобы дамп можно было
        // импортировать на сервер с другой репликацией без конфликтов.
        $argv[] = '--no-tablespaces';
        $argv[] = '--quick';
        $argv[] = '--hex-blob';
        $argv[] = '--set-gtid-purged=OFF';

        // kind определяет содержимое: schema — только структура (--no-data),
        // data — только данные (--no-create-info), full — всё вместе.
        if ($kind === 'schema') {
            $argv[] = '--no-data';
        } elseif ($kind === 'data') {
            $argv[] = '--no-create-info';
        } else {
            // --single-transaction только для full: консистентный снимок InnoDB
            // без блокировки таблиц (для schema/data снимок не нужен).
            $argv[] = '--single-transaction';
        }

        // Процедуры/функции, триггеры и события по умолчанию включаются —
        // полный дамп должен восстанавливать базу целиком, а не только таблицы.
        if ($cfg->opt('routines', true)) {
            $argv[] = '--routines';
        }
        if ($cfg->opt('triggers', true)) {
            $argv[] = '--triggers';
        }
        if ($cfg->opt('events', true)) {
            $argv[] = '--events';
        }

        // --where: фильтр строк, применяется mysqldump к выгружаемым таблицам
        // (имеет смысл для data/full; для schema данных всё равно нет).
        $where = (string) $cfg->opt('where', '');
        if ($where !== '') {
            $argv[] = '--where=' . $where;
        }

        // --ignore-table повторяемый: исключаем отдельные таблицы (например,
        // кэш/сессии); значение обязано быть в формате db.table.
        foreach ((array) $cfg->opt('ignore-table', []) as $ignore) {
            $argv[] = '--ignore-table=' . $ignore;
        }

        // Произвольные флаги mysqldump: сначала из конфига (mysqldump_extra),
        // затем из CLI (--extra) — CLI идёт позже и может переопределить конфиг.
        foreach ((array) $cfg->opt('mysqldump_extra', []) as $extra) {
            $argv[] = (string) $extra;
        }
        foreach ((array) $cfg->opt('extra', []) as $extra) {
            $argv[] = (string) $extra;
        }

        // Имя БД — позиционный аргумент; после него могут идти имена таблиц.
        $argv[] = $db['name'];

        // Белый список таблиц через запятую: если задан, дампятся только они.
        $tables = (string) $cfg->opt('tables', '');
        if ($tables !== '') {
            foreach (explode(',', $tables) as $table) {
                $table = trim($table);
                if ($table !== '') {
                    $argv[] = $table;
                }
            }
        }

        return $argv;
    }

    /**
     * Short git hash of the current HEAD for the manifest: ties the dump to the
     * project's code version. Missing git or a failed query is no reason to fail,
     * so null is returned instead.
     *
     * Side effects: runs the external git process (read-only).
     *
     * @return string|null short commit hash, or null when git is unavailable/not a repository
     */
    private static function gitRev(): ?string
    {
        if (!Fs::hasBinary('git')) {
            return null;
        }
        $res = Command::capture(['git', 'rev-parse', '--short', 'HEAD']);
        return $res->ok() ? trim($res->stdout) : null;
    }
}
