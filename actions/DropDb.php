<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * drop-db action: irreversible database deletion (admin, destructive).
 *
 * Run: php start.php drop-db [--name=name] [--yes] [--dry-run].
 * Without --yes it asks for interactive confirmation (default "no").
 * Executes DROP DATABASE IF EXISTS as the administrator. Use with maximum
 * caution: database data and structure are deleted with no way back.
 */

use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Menu;
use DbTools\Lib\Sql;

/**
 * Destructive administrative action that drops a database with mandatory
 * confirmation (interactive prompt or the --yes flag for scripts).
 */
final class DropDb extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'drop-db'
     */
    public function name(): string
    {
        return 'drop-db';
    }

    /**
     * Title for the interactive menu/help (marked as destructive).
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Drop database (admin, destructive)';
    }

    /**
     * CLI options of the action for help/menu.
     *
     * @return array<string,string> option name => description
     */
    public function options(): array
    {
        return ['name' => 'override database name'];
    }

    /**
     * Drops the database after the user confirms.
     * Requires admin rights (execSql uses the admin by default).
     *
     * Side effects: IRREVERSIBLY deletes the database with all its data; writes to the log;
     * without --yes it asks an interactive question. --dry-run only prints the SQL.
     * Declining the confirmation is not an error: 0 is returned ("Cancelled").
     *
     * @param Config $cfg  configuration (database name, admin credentials, yes/dry-run flags)
     * @param array  $opts CLI options (values are read via $cfg->opt)
     * @return int 0 — success/cancellation/dry-run, 1 — SQL execution error
     */
    public function run(Config $cfg, array $opts): int
    {
        $db = $cfg->db();
        $name = (string) $cfg->opt('name', $db['name']);
        // Имя закавычено Sql::ident: защита от инъекции и корректная работа
        // с именами, содержащими спецсимволы или совпадающими с ключевыми словами.
        $sql = 'DROP DATABASE IF EXISTS ' . Sql::ident($name);

        // --dry-run важнее подтверждения: показываем SQL и выходим без вопросов.
        if (!empty($cfg->cli()['dry-run'])) {
            Log::info('[dry-run] ' . $sql);
            return 0;
        }

        // Деструктивная операция требует явного согласия: --yes для
        // неинтерактивных сценариев, иначе вопрос с дефолтом «нет» (false) —
        // случайный Enter не удалит базу.
        if (empty($cfg->cli()['yes'])) {
            if (!Menu::confirm("Really DROP database '{$name}'? This cannot be undone", false)) {
                return $this->ok('Cancelled.');
            }
        }

        Log::step("Dropping database {$name}");
        $res = $this->execSql($cfg, [$sql]);
        if (!$res['ok']) {
            return $this->fail('drop-db failed: ' . $res['msg']);
        }
        return $this->ok("Database {$name} dropped.");
    }
}
