<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Действие create-db: создание базы данных с правами администратора.
 *
 * Запуск: php start.php create-db [--name=имя] [--collation=...] [--dry-run].
 * Выполняет CREATE DATABASE IF NOT EXISTS с кодировкой из конфигурации
 * (по умолчанию utf8mb4) и коллацией <charset>_unicode_ci. Идемпотентно:
 * существующая БД не пересоздаётся и не повреждается.
 */

use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Sql;

/**
 * Административное действие создания БД через execSql (PDO или mysql-клиент
 * транспорта с админскими реквизитами).
 */
final class CreateDb extends Action
{
    /**
     * Машинное имя действия для CLI.
     *
     * @return string всегда 'create-db'
     */
    public function name(): string
    {
        return 'create-db';
    }

    /**
     * Заголовок для интерактивного меню/справки.
     *
     * @return string описание действия
     */
    public function title(): string
    {
        return 'Create database (admin)';
    }

    /**
     * CLI-опции действия для справки/меню.
     *
     * @return array<string,string> имя опции => описание
     */
    public function options(): array
    {
        return [
            'collation' => 'database collation (default utf8mb4_unicode_ci)',
            'name' => 'override database name',
        ];
    }

    /**
     * Создаёт БД (если её ещё нет) с заданными кодировкой и коллацией.
     * Требует админских прав: execSql по умолчанию берёт реквизиты админа.
     *
     * Побочные эффекты: создаёт базу данных на сервере; пишет в лог.
     * В режиме --dry-run только печатает SQL, ничего не выполняя.
     *
     * @param Config $cfg  конфигурация (имя БД, charset, админские реквизиты)
     * @param array  $opts опции CLI (значения читаются через $cfg->opt)
     * @return int 0 — успех/отмена dry-run, 1 — ошибка выполнения SQL
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
