<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Действие drop-db: безвозвратное удаление базы данных (админ, деструктивно).
 *
 * Запуск: php start.php drop-db [--name=имя] [--yes] [--dry-run].
 * Без --yes запрашивает интерактивное подтверждение (по умолчанию «нет»).
 * Выполняет DROP DATABASE IF EXISTS от администратора. Используйте с максимальной
 * осторожностью: данные и структура БД удаляются без возможности отката.
 */

use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Menu;
use DbTools\Lib\Sql;

/**
 * Деструктивное административное действие удаления БД с обязательным
 * подтверждением (интерактивным или флагом --yes для скриптов).
 */
final class DropDb extends Action
{
    /**
     * Машинное имя действия для CLI.
     *
     * @return string всегда 'drop-db'
     */
    public function name(): string
    {
        return 'drop-db';
    }

    /**
     * Заголовок для интерактивного меню/справки (помечен как деструктивный).
     *
     * @return string описание действия
     */
    public function title(): string
    {
        return 'Drop database (admin, destructive)';
    }

    /**
     * CLI-опции действия для справки/меню.
     *
     * @return array<string,string> имя опции => описание
     */
    public function options(): array
    {
        return ['name' => 'override database name'];
    }

    /**
     * Удаляет базу данных после подтверждения пользователя.
     * Требует админских прав (execSql по умолчанию использует админа).
     *
     * Побочные эффекты: БЕЗВОЗВРАТНО удаляет БД со всеми данными; пишет в лог;
     * без --yes задаёт интерактивный вопрос. --dry-run только печатает SQL.
     * Отказ от подтверждения — не ошибка: возвращается 0 («Cancelled»).
     *
     * @param Config $cfg  конфигурация (имя БД, админские реквизиты, флаги yes/dry-run)
     * @param array  $opts опции CLI (значения читаются через $cfg->opt)
     * @return int 0 — успех/отмена/dry-run, 1 — ошибка выполнения SQL
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
