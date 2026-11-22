<?php

/**
 * Юнит-тесты SQL-хелперов DbTools\Lib\Sql: безопасное quoting
 * идентификаторов (ident), экранирование строковых литералов (quoteString)
 * и константа нормализации sql_mode. Это защита от SQL-инъекций в
 * админ-действиях (create-db/create-user/drop-db), где имена БД и
 * пользователей приходят из CLI/конфига и подставляются в SQL. Без БД.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Sql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет «белый список» имён и корректное экранирование строк —
 * любой обход этих проверок означал бы возможность инъекции в
 * CREATE DATABASE / CREATE USER / DROP DATABASE.
 */
final class SqlTest extends TestCase
{
    /**
     * Безопасные имена (буквы, цифры, '_', '$') оборачиваются в backtick
     * без изменений — валидные MySQL-идентификаторы проходят как есть.
     */
    public function testIdentAcceptsSafeNames(): void
    {
        $this->assertSame('`wp_options`', Sql::ident('wp_options'));
        $this->assertSame('`db_1`', Sql::ident('db_1'));
        $this->assertSame('`$var`', Sql::ident('$var'));
    }

    /**
     * Опасные имена ОБЯЗАНЫ отклоняться исключением, а не «экранироваться»:
     * пробелы, ';', backtick, кавычки и дефис позволяют вырваться из
     * идентификатора и выполнить произвольный SQL (напр. db;DROP TABLE x).
     */
    #[DataProvider('unsafeIdents')]
    public function testIdentRejectsUnsafe(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sql::ident($bad);
    }

    /**
     * Набор инъекционных/невалидных имён для testIdentRejectsUnsafe.
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
     * quoteString экранирует одинарную кавычку и обратный слеш —
     * строковый литерал (пароль в IDENTIFIED BY и т.п.) не может
     * «закрыться» раньше времени и оборвать SQL.
     */
    public function testQuoteStringEscapesQuoteAndBackslash(): void
    {
        $this->assertSame("'abc'", Sql::quoteString('abc'));
        $this->assertSame("'a\\'b'", Sql::quoteString("a'b"));
        $this->assertSame("'a\\\\b'", Sql::quoteString('a\\b'));
    }

    /**
     * NORMALIZE_SQL_MODE — сессионный SET SESSION sql_mode, УБИРАЮЩИЙ флаг
     * NO_BACKSLASH_ESCAPES из режима сервера: quoteString экранирует '\'
     * и кавычки обратным слешем (дефолтный стиль MySQL), и при включённом
     * NO_BACKSLASH_ESCAPES такие литералы (пароли с кавычками) развалились
     * бы или открыли инъекцию. Тест фиксирует оба компонента выражения.
     */
    public function testNormalizeSqlModeTargetsBackslashEscapes(): void
    {
        $this->assertStringContainsString('NO_BACKSLASH_ESCAPES', Sql::NORMALIZE_SQL_MODE);
        $this->assertStringContainsString('SET SESSION sql_mode', Sql::NORMALIZE_SQL_MODE);
    }
}
