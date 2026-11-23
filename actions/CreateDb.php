<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * create-db action: creating a database with administrator privileges.
 *
 * Run: php start.php create-db [--name=name] [--collation=...] [--dry-run].
 * Executes CREATE DATABASE IF NOT EXISTS with the charset from the configuration
 * (utf8mb4 by default) and the <charset>_unicode_ci collation. Idempotent:
 * an existing database is neither recreated nor damaged.
 */

use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Sql;

/**
 * Administrative database-creation action via execSql (PDO or the transport's
 * mysql client with admin credentials).
 */
final class CreateDb extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'create-db'
     */
    public function name(): string
    {
        return 'create-db';
    }

    /**
     * Title for the interactive menu/help.
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Create database (admin)';
    }

    /**
     * CLI options of the action for help/menu.
     *
     * @return array<string,string> option name => description
     */
    public function options(): array
    {
        return [
            'collation' => 'database collation (default utf8mb4_unicode_ci)',
            'name' => 'override database name',
        ];
    }

    /**
     * Creates the database (if it does not exist yet) with the given charset and collation.
     * Requires admin rights: execSql takes admin credentials by default.
     *
     * Side effects: creates a database on the server; writes to the log.
     * In --dry-run mode it only prints the SQL without executing anything.
     *
     * @param Config $cfg  configuration (database name, charset, admin credentials)
     * @param array  $opts CLI options (values are read via $cfg->opt)
     * @return int 0 — success/dry-run cancellation, 1 — SQL execution error
     */
    public function run(Config $cfg, array $opts): int
    {
        $db = $cfg->db();
        // --name позволяет создать БД с именем, отличным от конфигурационного.
        $name = (string) $cfg->opt('name', $db['name']);
        $charset = $db['charset'];
        // Коллация по умолчанию выводится из кодировки: utf8mb4 -> utf8mb4_unicode_ci.
        $collation = (string) $cfg->opt('collation', $charset . '_unicode_ci');

        // Все идентификаторы закавычены через Sql::ident (backtick-экранирование):
        // имя БД приходит из CLI/конфига и не должно позволить SQL-инъекцию
        // или сломаться о зарезервированное слово/спецсимволы.
        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            Sql::ident($name),
            Sql::ident($charset),
            Sql::ident($collation)
        );

        // --dry-run: показываем SQL без подключения к серверу.
        if (!empty($cfg->cli()['dry-run'])) {
            Log::info('[dry-run] ' . $sql);
            return 0;
        }

        Log::step("Creating database {$name}");
        // execSql выполнит от админа (useAdmin=true по умолчанию) и заранее
        // нормализует sql_mode — см. Action::execSql.
        $res = $this->execSql($cfg, [$sql]);
        if (!$res['ok']) {
            return $this->fail('create-db failed: ' . $res['msg']);
        }
        return $this->ok("Database {$name} ready.");
    }
}
