<?php

/**
 * Unit tests for the SQL helpers in DbTools\Lib\Sql: safe identifier quoting
 * (ident), string literal escaping (quoteString) and the sql_mode
 * normalization constant. This is the defense against SQL injection in
 * admin actions (create-db/create-user/drop-db), where DB and user names come
 * from the CLI/config and are interpolated into SQL. No DB.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Sql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the name "allowlist" and correct string escaping —
 * any bypass of these checks would mean a possible injection into
 * CREATE DATABASE / CREATE USER / DROP DATABASE.
 */
final class SqlTest extends TestCase
{
    /**
     * Safe names (letters, digits, '_', '$') are wrapped in backticks
     * unchanged — valid MySQL identifiers pass through as is.
     */
    public function testIdentAcceptsSafeNames(): void
    {
        $this->assertSame('`wp_options`', Sql::ident('wp_options'));
        $this->assertSame('`db_1`', Sql::ident('db_1'));
        $this->assertSame('`$var`', Sql::ident('$var'));
    }

    /**
     * Dangerous names MUST be rejected with an exception, not "escaped":
     * spaces, ';', backticks, quotes and the dash allow breaking out of the
     * identifier and executing arbitrary SQL (e.g. db;DROP TABLE x).
     */
    #[DataProvider('unsafeIdents')]
    public function testIdentRejectsUnsafe(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sql::ident($bad);
    }

    /**
     * Set of injection/invalid names for testIdentRejectsUnsafe.
     *
     * @return array<string,array{string}>
     */
    public static function unsafeIdents(): array
    {
        return [
            'empty' => [''],
            'space' => ['a b'],
            'semicolon' => ['db;DROP TABLE x'],
            'backtick' => ['a`b'],
            'quote' => ["a'b"],
            'dash' => ['a-b'],
        ];
    }

    /**
     * quoteString escapes the single quote and the backslash —
     * a string literal (a password in IDENTIFIED BY etc.) cannot
     * "close itself" prematurely and break the SQL.
     */
    public function testQuoteStringEscapesQuoteAndBackslash(): void
    {
        $this->assertSame("'abc'", Sql::quoteString('abc'));
        $this->assertSame("'a\\'b'", Sql::quoteString("a'b"));
        $this->assertSame("'a\\\\b'", Sql::quoteString('a\\b'));
    }

    /**
     * NORMALIZE_SQL_MODE is a SET SESSION sql_mode statement that REMOVES the
     * NO_BACKSLASH_ESCAPES flag from the server mode: quoteString escapes '\'
     * and quotes with a backslash (MySQL's default style), and with
     * NO_BACKSLASH_ESCAPES enabled such literals (passwords containing quotes)
     * would either break or open an injection. The test pins both components
     * of the expression.
     */
    public function testNormalizeSqlModeTargetsBackslashEscapes(): void
    {
        $this->assertStringContainsString('NO_BACKSLASH_ESCAPES', Sql::NORMALIZE_SQL_MODE);
        $this->assertStringContainsString('SET SESSION sql_mode', Sql::NORMALIZE_SQL_MODE);
    }
}
