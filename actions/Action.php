<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Base class for all DBitter actions (php start.php <action>).
 *
 * Holds the shared infrastructure: transport selection (local/ssh/wsl), building
 * mysql/mysqldump argv without the password in argv, running SQL as admin or as
 * the application user, the --dry-run mode and unified exit codes.
 * Concrete scenarios (export, import, create-db, etc.) inherit from it.
 */

use DbTools\Lib\Config;
use DbTools\Transport\Transport;

/**
 * Abstract CLI-tool action.
 *
 * Defines the contract (name, title, options, run) and reusable
 * protected helpers for working with MySQL through the selected transport.
 */
abstract class Action
{
    /**
     * Machine name of the action for CLI invocation: php start.php <name>.
     *
     * @return string unique action identifier (for example, 'export')
     */
    abstract public function name(): string;

    /**
     * Human-readable title for the interactive menu and help.
     *
     * @return string one-line description of the action
     */
    abstract public function title(): string;

    /**
     * List of CLI options supported by the action for help/menu display.
     * No options by default; subclasses override when needed.
     *
     * @return array<string,string> option name => description (for help/menu)
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Action entry point: runs the scenario taking configuration and options into account.
     *
     * @param Config $cfg  loaded project/environment configuration
     * @param array  $opts parsed command-line options
     * @return int process exit code: 0 — success, non-zero — error
     */
    abstract public function run(Config $cfg, array $opts): int;

    /**
     * Creates the transport from the configuration (local, ssh or wsl).
     * The transport decides where and how the mysql/mysqldump clients run.
     *
     * @param Config $cfg configuration with connection and transport parameters
     * @return Transport ready-to-use transport for launching client commands
     */
    protected function transport(Config $cfg): Transport
    {
        return Transport::make($cfg);
    }

    /**
     * Base argv for the mysql/mysqldump client: binary, socket or host:port,
     * user and connection charset. The password NEVER goes in here —
     * it is passed via the MYSQL_PWD environment variable so it does not show up
     * in argv or in the system process list.
     *
     * @param Config      $cfg          database connection configuration
     * @param bool        $dump         true — build mysqldump args, false — mysql
     * @param string|null $userOverride substitute the user (for example, with the admin one)
     * @return list<string> command arguments without the password
     */
    protected function clientBase(Config $cfg, bool $dump = false, ?string $userOverride = null): array
    {
        $db = $cfg->db();
        $t = $this->transport($cfg);
        $bin = $dump ? $t->mysqldumpBin() : $t->mysqlBin();
        $argv = [$bin];
        // Socket имеет приоритет над host:port: прямое соединение через
        // unix-сокет быстрее и не зависит от сетевого стека (актуально для WSL).
        if (!empty($db['socket'])) {
            $argv[] = '--socket=' . $db['socket'];
        } else {
            $argv[] = '--host=' . $db['host'];
            $argv[] = '--port=' . $db['port'];
        }
        $argv[] = '--user=' . ($userOverride ?? $db['user']);
        $argv[] = '--default-character-set=' . $db['charset'];
        // mysql_extra добавляется ТОЛЬКО клиенту mysql: это флаги интерактивного
        // клиента (например, --init-command), которые mysqldump не понимает
        // и упал бы с ошибкой неизвестной опции. Для дампа есть mysqldump_extra.
        if (!$dump) {
            foreach ((array) $cfg->opt('mysql_extra', []) as $extra) {
                $argv[] = (string) $extra;
            }
        }
        return $argv;
    }

    /**
     * Executes SQL statements as the admin (or as the application user).
     * If the server is reachable from local PHP — works through PDO; otherwise
     * feeds SQL as a stream into the mysql client's stdin via the transport (ssh/wsl).
     * Before execution it normalizes sql_mode so that string escaping
     * in the Sql helpers is guaranteed to match the server's expectations.
     *
     * Side effects: changes the database state (DDL/DML), may write to the log.
     *
     * @param Config       $cfg        connection configuration
     * @param list<string> $statements SQL statements to execute sequentially
     * @param bool         $useAdmin   true — administrator credentials, false — database user's
     * @return array{ok:bool,msg:string} ok — success, msg — server error text (empty on success)
     */
    protected function execSql(Config $cfg, array $statements, bool $useAdmin = true): array
    {
        $db = $cfg->db();
        $t = $this->transport($cfg);
        $creds = $useAdmin ? $cfg->admin() : ['user' => $db['user'], 'pass' => $db['pass']];
        // Гарантируем наши предположения об экранировании даже если на сервере
        // включён NO_BACKSLASH_ESCAPES: Sql::NORMALIZE_SQL_MODE убирает этот режим
        // на время сессии, иначе обратные слеши в строках трактовались бы буквально
        // и закавыченные пароли/идентификаторы могли бы сломать SQL или стать инъекцией.
        $statements = array_merge([\DbTools\Lib\Sql::NORMALIZE_SQL_MODE], $statements);

        // Быстрый путь: сервер доступен напрямую из локального PHP — используем PDO,
        // это даёт точные сообщения об ошибках без парсинга вывода клиента.
        if ($t->pdoReachable() && extension_loaded('pdo_mysql')) {
            try {
                $pdo = \DbTools\Lib\Sql::connect(
                    $db['host'],
                    (int) $db['port'],
                    (string) $creds['user'],
                    (string) $creds['pass'],
                    null,
                    $db['charset'],
                    $db['socket'] ?? null
                );
                foreach ($statements as $sql) {
                    \DbTools\Lib\Sql::exec($pdo, $sql);
                }
                return ['ok' => true, 'msg' => ''];
            } catch (\Throwable $e) {
                return ['ok' => false, 'msg' => $e->getMessage()];
            }
        }

        // Запасной путь: PDO недоступен (например, MySQL внутри WSL/на удалёнке) —
        // склеиваем стейтменты через ';' и подаём их в stdin mysql через транспорт.
        // Пароль снова только в MYSQL_PWD, не в argv.
        $argv = $this->clientBase($cfg, false, (string) $creds['user']);
        $env = ['MYSQL_PWD' => (string) $creds['pass']];
        $launch = $t->launch($argv, $env);
        // У каждого стейтмента срезаем хвостовые ';' и пробелы, затем добавляем
        // единственный разделитель — чтобы не получить пустых команд ';;'.
        $sql = implode(";\n", array_map(static fn(string $s): string => rtrim($s, "; \t\n\r"), $statements)) . ";";
        $res = \DbTools\Lib\Command::capture($launch['cmd'], $launch['env'], $sql);
        return ['ok' => $res->ok(), 'msg' => trim($res->output())];
    }

    /**
     * Builds the environment with the password for the mysql/mysqldump client.
     * MYSQL_PWD is the only channel for passing the password: it is invisible in argv,
     * in the process list and (after masking) in the logs.
     *
     * @param Config      $cfg  configuration (database user password by default)
     * @param string|null $pass explicit password (for example, the admin one) when the default is not wanted
     * @return array<string,string> environment variables for launching the process
     */
    protected function pwdEnv(Config $cfg, ?string $pass = null): array
    {
        return ['MYSQL_PWD' => $pass ?? $cfg->db()['pass']];
    }

    /**
     * --dry-run mode: prints the command instead of executing it.
     * Shows both the outer launcher (wsl.exe/ssh) and the inner bash command;
     * all secrets from the configuration are masked in the output.
     *
     * Side effects: log output only, launches nothing.
     *
     * @param Config $cfg  configuration (dry-run flag and secrets list)
     * @param array  $argv client command arguments
     * @param array  $env  environment (including MYSQL_PWD)
     * @return bool true — dry-run is active and the command was printed (no need to execute)
     */
    protected function dryRun(Config $cfg, array $argv, array $env): bool
    {
        if (empty($cfg->cli()['dry-run'])) {
            return false;
        }
        $t = $this->transport($cfg);
        $launch = $t->launch($argv, $env);
        // Маскируем пароли/секреты до вывода: dry-run не должен раскрывать реквизиты.
        $display = \DbTools\Lib\Command::display($launch['cmd']);
        $masked = \DbTools\Lib\Command::mask($display, $cfg->secrets());
        \DbTools\Lib\Log::info('[dry-run] ' . $masked);
        $inner = \DbTools\Lib\Command::mask(\DbTools\Lib\Command::bashString($argv, $env), $cfg->secrets());
        \DbTools\Lib\Log::info('[dry-run] inner: ' . $inner);
        return true;
    }

    /**
     * Finishes the action with an error: writes the message to the log at error level.
     *
     * Side effects: outputs the error message to the log.
     *
     * @param string $msg error text for the user
     * @return int always 1 — unsuccessful CLI exit code
     */
    protected function fail(string $msg): int
    {
        \DbTools\Lib\Log::error($msg);
        return 1;
    }

    /**
     * Finishes the action with success: writes the final message to the log.
     *
     * Side effects: outputs the message to the log at info level.
     *
     * @param string $msg final message for the user
     * @return int always 0 — successful CLI exit code
     */
    protected function ok(string $msg): int
    {
        \DbTools\Lib\Log::info($msg);
        return 0;
    }
}
