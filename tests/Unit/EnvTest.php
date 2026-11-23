<?php

/**
 * Unit tests for the .env parser (DbTools\Lib\Env::parse): the export prefix,
 * single/double quotes, escape sequences, comments, and empty values.
 * Important for the env/laravel extractors: incorrectly parsing a password
 * containing '#' or '\n' inside quotes would break the DB connection.
 * Works on temporary files; no DB needed.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Env;
use PHPUnit\Framework\TestCase;

/**
 * Verifies parsing of all typical .env line formats and safe behavior
 * for a missing file (empty array instead of an exception).
 */
final class EnvTest extends TestCase
{
    /** Path to the current test's temporary .env (removed in tearDown). */
    private string $file = '';

    /** Deletes the temporary .env if the test created one. */
    protected function tearDown(): void
    {
        if ($this->file !== '') {
            @unlink($this->file);
        }
    }

    /**
     * The full set of formats: the export prefix is stripped; double quotes
     * process escapes ('\n' → newline), '#' inside quotes is part of the
     * value; single quotes are taken literally; a trailing comment on an
     * unquoted value is discarded; blank lines and comments do not become
     * keys; EMPTY= yields an empty string.
     */
    public function testParseQuotesExportAndComments(): void
    {
        $this->file = sys_get_temp_dir() . '/dbt_env_' . bin2hex(random_bytes(3)) . '.env';
        file_put_contents($this->file, implode("\n", [
            '# leading comment',
            'export DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            "DB_PASSWORD=\"p@ss\\nw0rd\"",   // двойные кавычки, escape обрабатывается
            "DB_USER='app'",                // одинарные кавычки
            'DB_NAME=mydb   # trailing comment',
            '',
            '   ',
            'EMPTY=',
        ]));
        $v = Env::parse($this->file);
        $this->assertSame('127.0.0.1', $v['DB_HOST']);   // префикс export снят
        $this->assertSame('3306', $v['DB_PORT']);
        $this->assertSame("p@ss\nw0rd", $v['DB_PASSWORD']); // \n разэкранирован, # сохранён
        $this->assertSame('app', $v['DB_USER']);
        $this->assertSame('mydb', $v['DB_NAME']);        // хвостовой комментарий отброшен
        $this->assertSame('', $v['EMPTY']);
        $this->assertArrayNotHasKey('#', $v);
    }

    /**
     * A missing .env is not an error: parse() returns an empty array
     * so extractors can gently fall back to other sources.
     */
    public function testParseMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], Env::parse(sys_get_temp_dir() . '/dbt_nope_' . bin2hex(random_bytes(3)) . '.env'));
    }
}
