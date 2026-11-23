<?php

declare(strict_types=1);

namespace DbTools\Lib;

use PDO;

/**
 * Thin layer for working with MySQL through PDO: connecting (TCP or unix
 * socket), safe quoting of MySQL identifiers and string literals for DDL
 * (CREATE DATABASE / CREATE USER / DROP), running queries with logging,
 * listing tables. Used by the pdo transport and the create-db/create-user/
 * drop-db/doctor actions; SQL logging goes through Log::debug.
 */
final class Sql
{
    /**
     * SQL expression that strips NO_BACKSLASH_ESCAPES from the session sql_mode.
     * Why: quoteString() escapes special characters with a backslash (the MySQL
     * default style). If the server runs with NO_BACKSLASH_ESCAPES, the slash
     * stops being an escape character, and our literals (passwords containing
     * quotes, etc.) would either break or open an SQL-injection hole. The three
     * nested REPLACE calls remove the flag at any position in the list: at the
     * start ("FLAG,"), in the middle/end (",FLAG") and as the only value.
     * Executed before any DDL that embeds string literals.
     */
    public const NORMALIZE_SQL_MODE =
        "SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@sql_mode,"
        . "'NO_BACKSLASH_ESCAPES,',''),',NO_BACKSLASH_ESCAPES',''),'NO_BACKSLASH_ESCAPES','')";

    /**
     * Opens a PDO connection to MySQL. When a socket is given, the connection
     * goes over the unix socket (host/port are ignored), otherwise over TCP.
     * Error mode is exceptions, the connect timeout is 10 seconds so that an
     * unreachable server does not hang the CLI for a long time.
     *
     * @param string $host MySQL host (for TCP).
     * @param int $port MySQL port (for TCP).
     * @param string $user DB user.
     * @param string $pass Password (passed to PDO, not to the command line).
     * @param string|null $dbname Database name; null/'' — connect without selecting a DB.
     * @param string $charset Connection charset (utf8mb4 by default).
     * @param string|null $socket unix socket path; a non-empty one takes priority over host/port.
     * @return PDO A ready connection.
     * @throws \PDOException If the connection could not be established (errors are exceptions).
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
     * Quotes a MySQL identifier with backticks, but only after a whitelist
     * check against [A-Za-z0-9_$]: DB and user names are interpolated into DDL
     * directly (placeholders are not available there), so anything outside the
     * whitelist is rejected rather than escaped.
     *
     * @param string $name Database, table or user name.
     * @return string Identifier in backticks, ready for SQL.
     * @throws \InvalidArgumentException If the name is empty or contains unsafe characters.
     */
    public static function ident(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9_$]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$name}");
        }
        return '`' . $name . '`';
    }

    /**
     * Runs an SQL statement without fetching rows, first writing it to the
     * debug log (visible with --verbose and in the log file) — this makes it
     * easier to analyze why a DDL statement failed.
     *
     * Side effects: writes a line via Log::debug; executes the query on the server.
     *
     * @param PDO $pdo Connection.
     * @param string $sql Query (DDL/DML).
     * @throws \PDOException On a server-side execution error.
     */
    public static function exec(PDO $pdo, string $sql): void
    {
        Log::debug('SQL: ' . $sql);
        $pdo->exec($sql);
    }

    /**
     * Quotes a string literal for SQL in single quotes, escaping the backslash
     * and the single quote (the MySQL default escaping style). Correctness is
     * guaranteed only with NO_BACKSLASH_ESCAPES turned off — for that purpose
     * NORMALIZE_SQL_MODE is executed before the DDL.
     *
     * @param string $value Arbitrary value (e.g. a user password).
     * @return string A 'text' literal with special characters escaped.
     */
    public static function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Returns the first column of the first result row — convenient for checks
     * like SELECT COUNT(*) / SHOW-style queries in doctor.
     *
     * @param PDO $pdo Connection.
     * @param string $sql Query returning at least one column.
     * @return mixed The value of the first column, or false when there are no rows.
     * @throws \PDOException On a query error.
     */
    public static function scalar(PDO $pdo, string $sql): mixed
    {
        return $pdo->query($sql)->fetchColumn();
    }

    /**
     * List of tables in the given DB via information_schema (parameterized,
     * the schema name is a placeholder), sorted by name. Used to count and
     * enumerate tables after an import and in doctor.
     *
     * @param PDO $pdo Connection.
     * @param string $dbname Schema (DB) name.
     * @return list<string> Table names in alphabetical order.
     * @throws \PDOException On a query error.
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
