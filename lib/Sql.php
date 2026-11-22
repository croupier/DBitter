<?php

declare(strict_types=1);

namespace DbTools\Lib;

use PDO;

/**
 * Тонкий слой работы с MySQL через PDO: подключение (TCP или unix-socket),
 * безопасное квотирование идентификаторов и строковых литералов для DDL
 * (CREATE DATABASE / CREATE USER / DROP), выполнение запросов с логированием,
 * список таблиц. Используется транспортом pdo и действиями create-db/
 * create-user/drop-db/doctor; SQL-логи идут через Log::debug.
 */
final class Sql
{
    /**
     * SQL-выражение, убирающее NO_BACKSLASH_ESCAPES из сессионного sql_mode.
     * Зачем: quoteString() экранирует спецсимволы обратным слэшем (стиль
     * MySQL по умолчанию). Если сервер работает с NO_BACKSLASH_ESCAPES,
     * слэш перестаёт быть escape-символом, и наши литералы (пароли с
     * кавычками и т.п.) развалились бы или открыли SQL-инъекцию. Три
     * вложенных REPLACE удаляют флаг в любой позиции списка: в начале
     * ("FLAG,"), в середине/конце (",FLAG") и как единственное значение.
     * Выполняется перед любым DDL со встроенными строковыми литералами.
     */
    public const NORMALIZE_SQL_MODE =
        "SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@sql_mode,"
        . "'NO_BACKSLASH_ESCAPES,',''),',NO_BACKSLASH_ESCAPES',''),'NO_BACKSLASH_ESCAPES','')";

    /**
     * Открывает PDO-соединение с MySQL. Если задан socket — подключение
     * идёт через unix-socket (host/port игнорируются), иначе по TCP.
     * Режим ошибок — исключения, таймаут соединения — 10 секунд, чтобы
     * недоступный сервер не подвешивал CLI надолго.
     *
     * @param string $host Хост MySQL (для TCP).
     * @param int $port Порт MySQL (для TCP).
     * @param string $user Пользователь БД.
     * @param string $pass Пароль (передаётся в PDO, не в командную строку).
     * @param string|null $dbname Имя БД; null/'' — подключение без выбора базы.
     * @param string $charset Кодировка соединения (по умолчанию utf8mb4).
     * @param string|null $socket Путь unix-socket; непустой имеет приоритет над host/port.
     * @return PDO Готовое соединение.
     * @throws \PDOException Если подключиться не удалось (ошибки — исключения).
     */
    public static function connect(
        string $host,
        int $port,
        string $user,
        string $pass,
        ?string $dbname = null,
        string $charset = 'utf8mb4',
        ?string $socket = null,
    ): PDO {
        $dsn = $socket !== null && $socket !== ''
            ? "mysql:unix_socket={$socket};charset={$charset}"
            : "mysql:host={$host};port={$port};charset={$charset}";
        if ($dbname !== null && $dbname !== '') {
            $dsn .= ";dbname={$dbname}";
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        return $pdo;
    }

    /**
     * Квотирует SQL-идентификатор обратными кавычками, но только после
     * проверки по белому списку [A-Za-z0-9_$]: имена БД/пользователей
     * подставляются в DDL напрямую (плейсхолдеры там недоступны), поэтому
     * всё вне списка отвергается, а не экранируется.
     *
     * @param string $name Имя БД, таблицы или пользователя.
     * @return string Идентификатор в обратных кавычках, готовый для SQL.
     * @throws \InvalidArgumentException Если имя пустое или содержит небезопасные символы.
     */
    public static function ident(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_$]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$name}");
        }
        return '`' . $name . '`';
    }

    /**
     * Выполняет SQL-statement без выборки, предварительно записывая его
     * в debug-лог (виден при --verbose и в лог-файле) — так проще
     * разбирать причины падения DDL.
     *
     * Побочные эффекты: пишет строку через Log::debug; выполняет запрос на сервере.
     *
     * @param PDO $pdo Соединение.
     * @param string $sql Запрос (DDL/DML).
     * @throws \PDOException При ошибке выполнения на сервере.
     */
    public static function exec(PDO $pdo, string $sql): void
    {
        Log::debug('SQL: ' . $sql);
        $pdo->exec($sql);
    }

    /**
     * Квотирует строковый литерал для SQL в одинарные кавычки, экранируя
     * обратный слэш и одинарную кавычку (стиль MySQL по умолчанию).
     * Корректность гарантируется только при выключенном NO_BACKSLASH_ESCAPES —
     * для этого перед DDL выполняется NORMALIZE_SQL_MODE.
     *
     * @param string $value Произвольное значение (например, пароль пользователя).
     * @return string Литерал вида 'текст' с экранированными спецсимволами.
     */
    public static function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Возвращает первое поле первой строки результата — удобно для
     * проверок вида SELECT COUNT(*) / SHOW-подобных запросов в doctor.
     *
     * @param PDO $pdo Соединение.
     * @param string $sql Запрос, возвращающий хотя бы одну колонку.
     * @return mixed Значение первой колонки или false, если строк нет.
     * @throws \PDOException При ошибке запроса.
     */
    public static function scalar(PDO $pdo, string $sql): mixed
    {
        return $pdo->query($sql)->fetchColumn();
    }

    /**
     * Список таблиц указанной БД через information_schema (параметризованно,
     * имя схемы — плейсхолдер), отсортированный по имени. Используется для
     * подсчёта/перечисления таблиц после импорта и в doctor.
     *
     * @param PDO $pdo Соединение.
     * @param string $dbname Имя схемы (БД).
     * @return list<string> Имена таблиц по алфавиту.
     * @throws \PDOException При ошибке запроса.
     */
    public static function tables(PDO $pdo, string $dbname): array
    {
        $stmt = $pdo->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name'
        );
        $stmt->execute([$dbname]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
