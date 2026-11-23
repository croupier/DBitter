<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * create-user action: creating a MySQL user and granting privileges on a database.
 *
 * Run: php start.php create-user [--name=database] [--user-host=localhost]
 * [--grant=ALL] [--dry-run]. Requires admin access. Executes
 * CREATE USER IF NOT EXISTS + GRANT + FLUSH PRIVILEGES; the password is taken
 * from the configuration and masked in logs/dry-run. Idempotent for an
 * existing account (the password is not changed).
 */

use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Sql;

/**
 * Administrative action: creates the user@host account and grants it
 * privileges on the given database via execSql with admin credentials.
 */
final class CreateUser extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'create-user'
     */
    public function name(): string
    {
        return 'create-user';
    }

    /**
     * Title for the interactive menu/help.
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Create user + grant on database (admin)';
    }

    /**
     * CLI options of the action for help/menu.
     *
     * @return array<string,string> option name => description
     */
    public function options(): array
    {
        return [
            'user-host' => 'host part of the account (default localhost)',
            'grant' => 'privileges (default ALL)',
        ];
    }

    /**
     * Creates the user and grants privileges on the database.
     * Requires admin rights (execSql uses the admin by default).
     *
     * Side effects: creates the account and grants on the server, runs
     * FLUSH PRIVILEGES; writes to the log (password masked). In --dry-run
     * it only prints the statements without executing anything.
     *
     * @param Config $cfg  configuration (database user/pass, admin credentials, secrets)
     * @param array  $opts CLI options (values are read via $cfg->opt)
     * @return int 0 — success/dry-run cancellation, 1 — unsafe grant or SQL error
     */
    public function run(Config $cfg, array $opts): int
    {
        $db = $cfg->db();
        $user = (string) $db['user'];
        $pass = (string) $db['pass'];
        $name = (string) $cfg->opt('name', $db['name']);
        $host = (string) $cfg->opt('user-host', $db['user_host']);
        $grant = strtoupper((string) $cfg->opt('grant', 'ALL'));
        // GRANT подставляется в SQL как есть (список привилегий нельзя закавычить
        // как идентификатор), поэтому допускаем только заглавные буквы, пробелы,
        // запятые и скобки — иначе значение из CLI стало бы SQL-инъекцией.
        if (preg_match('/^[A-Z ,()]+$/', $grant) !== 1) {
            return $this->fail('Unsafe grant value: ' . $grant);
        }

        // Аккаунт 'user'@'host': обе части — строковые литералы (Sql::quoteString),
        // имя БД в GRANT — идентификатор (Sql::ident); пароль тоже строковый литерал.
        // Экранирование согласовано с нормализацией sql_mode в Action::execSql.
        $account = Sql::quoteString($user) . '@' . Sql::quoteString($host);
        $statements = [
            sprintf('CREATE USER IF NOT EXISTS %s IDENTIFIED BY %s', $account, Sql::quoteString($pass)),
            sprintf('GRANT %s ON %s.* TO %s', $grant, Sql::ident($name), $account),
            'FLUSH PRIVILEGES',
        ];

        // --dry-run: печатаем стейтменты, обязательно маскируя пароль/секреты —
        // CREATE USER содержит пароль в открытом виде внутри SQL.
        if (!empty($cfg->cli()['dry-run'])) {
            foreach ($statements as $sql) {
                Log::info('[dry-run] ' . \DbTools\Lib\Command::mask($sql, $cfg->secrets()));
            }
            return 0;
        }

        Log::step("Creating user {$user}@{$host} with {$grant} on {$name}");
        $res = $this->execSql($cfg, $statements);
        if (!$res['ok']) {
            return $this->fail('create-user failed: ' . $res['msg']);
        }
        return $this->ok("User {$user}@{$host} ready.");
    }
}
