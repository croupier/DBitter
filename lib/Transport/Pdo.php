<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Sql;

/**
 * Транспорт «pdo»: работа с MySQL без бинарников mysql/mysqldump — только
 * SQL-действия (create-db, create-user, drop-db, doctor) через PDO-соединение
 * из локального PHP. Экспорт/импорт дампов недоступен: mysqldump через PDO
 * не заменить, для них нужны транспорты local/wsl/docker/ssh.
 */
final class Pdo extends Transport
{
    /**
     * Имя транспорта для логов и диагностики.
     *
     * @return string Всегда 'pdo'.
     */
    public function name(): string
    {
        return 'pdo';
    }

    /**
     * Запуск бинарников этим транспортом не поддерживается — намеренно
     * бросает исключение с подсказкой, какие транспорты выбрать для
     * экспорта/импорта. SQL-действия идут отдельным путём через Sql/PDO
     * и этот метод не вызывают.
     *
     * @param list<string> $argv Аргументы команды (игнорируются).
     * @param array<string,string> $env Переменные окружения (игнорируются).
     * @return array{cmd:string|list<string>,env:array<string,string>} Никогда не возвращается.
     * @throws \RuntimeException Всегда: target=pdo поддерживает только SQL-действия.
     */
    public function launch(array $argv, array $env = []): array
    {
        throw new \RuntimeException(
            'target=pdo supports SQL actions only (create-db, create-user, drop-db, doctor). '
            . 'For export/import use local, wsl, docker or ssh.'
        );
    }

    /**
     * Диагностика: проверяет загруженное расширение pdo_mysql и реальное
     * подключение к серверу. Соединение открывается БЕЗ выбора базы (null) —
     * проверяется доступность сервера и реквизиты, а не существование БД.
     * Версия сервера читается запросом SELECT VERSION().
     *
     * @return array{ok:bool,info:string} ok — соединение установлено; info — версия сервера или сообщение об ошибке.
     */
    public function doctor(): array
    {
        if (!extension_loaded('pdo_mysql')) {
            return ['ok' => false, 'info' => 'pdo_mysql extension not loaded'];
        }
        $db = $this->cfg->db();
        try {
            $pdo = Sql::connect($db['host'], (int) $db['port'], $db['user'], $db['pass'], null, $db['charset'], $db['socket'] ?? null);
            $version = (string) Sql::scalar($pdo, 'SELECT VERSION()');
            return ['ok' => true, 'info' => 'PDO connection OK | ' . $version];
        } catch (\Throwable $e) {
            // Любая ошибка подключения/запроса — диагноз, а не падение doctor.
            return ['ok' => false, 'info' => $e->getMessage()];
        }
    }
}
