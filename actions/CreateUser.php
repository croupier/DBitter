<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Действие create-user: создание пользователя MySQL и выдача прав на БД.
 *
 * Запуск: php start.php create-user [--name=база] [--user-host=localhost]
 * [--grant=ALL] [--dry-run]. Требует админского доступа. Выполняет
 * CREATE USER IF NOT EXISTS + GRANT + FLUSH PRIVILEGES; пароль берётся
 * из конфигурации и маскируется в логах/dry-run. Идемпотентно для
 * существующего аккаунта (пароль не меняется).
 */

use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Sql;

/**
 * Административное действие: создаёт аккаунт user@host и грантует ему
 * привилегии на указанную базу через execSql с админскими реквизитами.
 */
final class CreateUser extends Action
{
    /**
     * Машинное имя действия для CLI.
     *
     * @return string всегда 'create-user'
     */
    public function name(): string
    {
        return 'create-user';
    }

    /**
     * Заголовок для интерактивного меню/справки.
     *
     * @return string описание действия
     */
    public function title(): string
    {
        return 'Create user + grant on database (admin)';
    }

    /**
     * CLI-опции действия для справки/меню.
     *
     * @return array<string,string> имя опции => описание
     */
    public function options(): array
    {
        return [
            'user-host' => 'host part of the account (default localhost)',
            'grant' => 'privileges (default ALL)',
        ];
    }

    /**
     * Создаёт пользователя и выдаёт ему права на базу данных.
     * Требует админских прав (execSql по умолчанию использует админа).
     *
     * Побочные эффекты: создаёт аккаунт и гранты на сервере, выполняет
     * FLUSH PRIVILEGES; пишет в лог (пароль замаскирован). В --dry-run
     * только печатает стейтменты, ничего не выполняя.
     *
     * @param Config $cfg  конфигурация (user/pass БД, админские реквизиты, секреты)
     * @param array  $opts опции CLI (значения читаются через $cfg->opt)
     * @return int 0 — успех/отмена dry-run, 1 — небезопасный grant или ошибка SQL
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
