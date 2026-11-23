<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * doctor action: environment and MySQL connectivity diagnostics.
 *
 * Run: php start.php doctor. Checks PHP extensions (pdo_mysql, zlib),
 * presence of zstd/git, database configuration, transport health (local/ssh/wsl),
 * server connection and write access to the dump directory.
 * Changes nothing — it only reads and prints a report; the exit code depends
 * on the number of failed checks.
 */

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Fs;
use DbTools\Lib\Log;
use DbTools\Lib\Menu;
use DbTools\Lib\Sql;

/**
 * Diagnostic action: prints a sectioned report (environment, config,
 * transport, connectivity, dump storage) and counts the number of errors.
 */
final class Doctor extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'doctor'
     */
    public function name(): string
    {
        return 'doctor';
    }

    /**
     * Title for the interactive menu/help.
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Doctor — check environment & connectivity';
    }

    /**
     * Runs all checks and prints the report.
     *
     * Side effects: report output via Menu/Log; creates the dump directory
     * if it does not exist yet (Fs::ensureDir). The database state is not changed —
     * only SELECT VERSION() and reading the table list are executed.
     *
     * @param Config $cfg  project/environment configuration
     * @param array  $opts CLI options (unused by doctor)
     * @return int 0 — all checks passed, 1 — there are failed checks
     */
    public function run(Config $cfg, array $opts): int
    {
        $db = $cfg->db();
        $t = $this->transport($cfg);
        // Счётчик проваленных проверок: влияет только на итоговый код возврата.
        $fail = 0;

        Menu::line('== Environment ==');
        Menu::line(sprintf('  OS            : %s (%s)', Fs::os(), PHP_OS));
        Menu::line(sprintf('  PHP           : %s', PHP_VERSION));
        // pdo_mysql нужен для прямого подключения из PHP; zlib — для gzip-дампов.
        Menu::line(sprintf('  pdo_mysql     : %s', extension_loaded('pdo_mysql') ? 'yes' : 'NO'));
        Menu::line(sprintf('  zlib (gzip)   : %s', extension_loaded('zlib') ? 'yes' : 'NO'));
        // zstd не обязателен: без него экспорт всё равно работает через gzip.
        Menu::line(sprintf('  zstd binary   : %s', Fs::hasBinary('zstd') ? 'yes' : 'no (gzip still works)'));

        Menu::line('== Config ==');
        Menu::line(sprintf('  extractor     : %s', $db['source'] ?? '?'));
        Menu::line(sprintf('  engine        : %s', $db['engine']));
        Menu::line(sprintf('  database      : %s', $db['name']));
        Menu::line(sprintf('  host:port     : %s:%d%s', $db['host'], $db['port'], !empty($db['socket']) ? ' (socket ' . $db['socket'] . ')' : ''));
        Menu::line(sprintf('  user          : %s', $db['user']));
        Menu::line(sprintf('  prefix        : %s', $db['prefix'] ?: '(none)'));
        Menu::line(sprintf('  target        : %s', $t->name()));

        Menu::line('== Transport ==');
        // Транспорт сам проверяет себя (доступность wsl.exe/ssh, клиента и т.п.).
        $doc = $t->doctor();
        Menu::line(sprintf('  %-13s : %s %s', $t->name(), $doc['ok'] ? 'OK' : 'FAIL', $doc['info']));
        if (!$doc['ok']) {
            $fail++;
        }

        Menu::line('== Connectivity ==');
        // Предпочитаем PDO: даёт версию сервера и число таблиц одним подключением.
        if ($t->pdoReachable() && extension_loaded('pdo_mysql')) {
            try {
                $pdo = Sql::connect($db['host'], (int) $db['port'], $db['user'], $db['pass'], null, $db['charset'], $db['socket'] ?? null);
                $version = (string) Sql::scalar($pdo, 'SELECT VERSION()');
                $tables = Sql::tables($pdo, $db['name']);
                Menu::line(sprintf('  PDO           : OK | server %s | %d tables in %s', $version, count($tables), $db['name']));
            } catch (\Throwable $e) {
                Menu::line('  PDO           : FAIL | ' . $e->getMessage());
                $fail++;
            }
        } else {
            // PDO недоступен (MySQL в WSL/за ssh) — проверяем связность тем же
            // путём, которым пойдут export/import: клиент mysql через транспорт.
            // -N убирает заголовки колонок, -e выполняет запрос и завершается.
            $argv = array_merge($this->clientBase($cfg), ['-N', '-e', 'SELECT VERSION()']);
            $launch = $t->launch($argv, $this->pwdEnv($cfg));
            $res = Command::capture($launch['cmd'], $launch['env']);
            Menu::line(sprintf('  client        : %s | %s', $res->ok() ? 'OK' : 'FAIL', trim($res->output())));
            if (!$res->ok()) {
                $fail++;
            }
        }

        Menu::line('== Dump storage ==');
        $dir = $cfg->dumpDir();
        // Каталог создаётся заранее, чтобы проверка writability была честной.
        Fs::ensureDir($dir);
        $writable = is_writable($dir);
        Menu::line(sprintf('  dir           : %s (%s)', $dir, $writable ? 'writable' : 'NOT writable'));
        if (!$writable) {
            $fail++;
        }

        Menu::line();
        if ($fail === 0) {
            return $this->ok('All checks passed.');
        }
        return $this->fail("{$fail} check(s) failed.");
    }
}
