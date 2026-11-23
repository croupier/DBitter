<?php

/**
 * Unit tests for the DB parameter extractors that read application configs
 * (conf/env.php and conf/laravel.php). They verify parsing of DATABASE_URL
 * (mysql/pgsql), the classic DB_* keys, the default port, the error raised
 * when the DB name is missing, and the Laravel extractor's fallback to plain
 * .env when bootstrapping the application is impossible. No DB — runs on
 * temporary .env files.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The extractors are closures returned by the conf/*.php files; here they
 * are invoked directly with an options array (root/path/bootstrap), the way
 * Config::resolveDb does.
 */
final class ExtractorTest extends TestCase
{
    /** Tool root (DBitter) — conf/*.php is loaded relative to it. */
    private string $root;

    /** List of temporary .env files created by the test (for cleanup). */
    private array $files = [];

    /** Computes the tool root relative to the tests directory. */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** Deletes all of the test's temporary .env files. */
    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    /**
     * Helper: creates a temporary .env with the given content and remembers
     * it for deletion in tearDown.
     *
     * @return string path to the created file
     */
    private function envFile(string $content): string
    {
        $p = sys_get_temp_dir() . '/dbt_ex_' . bin2hex(random_bytes(3)) . '.env';
        file_put_contents($p, $content);
        $this->files[] = $p;
        return $p;
    }

    /** Loads the extractor closure from conf/env.php. */
    private function envExtractor(): \Closure
    {
        return require $this->root . '/conf/env.php';
    }

    /**
     * DATABASE_URL=mysql://user:pass@host:port/db is parsed into all
     * components: engine/host/port(int)/user/pass/name. The format is typical
     * for PaaS and docker environments.
     */
    public function testEnvDatabaseUrlMysql(): void
    {
        $ex = $this->envExtractor();
        $db = $ex(['root' => sys_get_temp_dir(), 'path' => $this->envFile('DATABASE_URL=mysql://u:pw@dbhost:3307/mydb'), 'bootstrap' => false]);
        $this->assertSame('mysql', $db['engine']);
        $this->assertSame('dbhost', $db['host']);
        $this->assertSame(3307, $db['port']);
        $this->assertSame('u', $db['user']);
        $this->assertSame('pw', $db['pass']);
        $this->assertSame('mydb', $db['name']);
    }

    /**
     * The postgres:// scheme in DATABASE_URL is recognized as engine=pgsql —
     * the tool must explicitly report a non-MySQL engine instead of silently
     * trying to connect with the mysql client.
     */
    public function testEnvDatabaseUrlPostgresDetected(): void
    {
        $ex = $this->envExtractor();
        $db = $ex(['root' => sys_get_temp_dir(), 'path' => $this->envFile('DATABASE_URL=postgres://u:pw@h:5432/pgdb'), 'bootstrap' => false]);
        $this->assertSame('pgsql', $db['engine']);
        $this->assertSame('pgdb', $db['name']);
    }

    /**
     * The classic Laravel-style DB_CONNECTION/DB_DATABASE/... keys are read
     * directly; without DB_PORT the MySQL default 3306 is substituted.
     */
    public function testEnvDbKeysAndDefaultPort(): void
    {
        $ex = $this->envExtractor();
        $db = $ex(['root' => sys_get_temp_dir(), 'path' => $this->envFile("DB_CONNECTION=mysql\nDB_DATABASE=app\nDB_USERNAME=usr\nDB_PASSWORD=sec\n"), 'bootstrap' => false]);
        $this->assertSame('app', $db['name']);
        $this->assertSame('usr', $db['user']);
        $this->assertSame('sec', $db['pass']);
        $this->assertSame(3306, $db['port']);
    }

    /**
     * Without a DB name the extractor must throw a RuntimeException —
     * silently dumping "something unknown" is not acceptable (an explicit
     * refusal is better).
     */
    public function testEnvThrowsWithoutDatabaseName(): void
    {
        $ex = $this->envExtractor();
        $this->expectException(\RuntimeException::class);
        $ex(['root' => sys_get_temp_dir(), 'path' => $this->envFile("DB_HOST=h\n"), 'bootstrap' => false]);
    }

    /**
     * With bootstrap=false the Laravel extractor does not attempt to load the
     * application and reads .env straight away via the env extractor.
     */
    public function testLaravelFallsBackToEnvWhenBootstrapDisabled(): void
    {
        $lex = require $this->root . '/conf/laravel.php';
        $db = $lex(['root' => sys_get_temp_dir(), 'path' => $this->envFile('DATABASE_URL=mysql://u:pw@h:3306/lar'), 'bootstrap' => false]);
        $this->assertSame('lar', $db['name']);
        $this->assertSame('mysql', $db['engine']);
    }

    /**
     * Even with bootstrap=true, if bootstrap/app.php or vendor/autoload.php
     * is missing under root (application not installed), bootstrapping is
     * impossible — the extractor must fall back to parsing plain .env instead
     * of crashing.
     */
    public function testLaravelFallsBackToEnvWhenNoVendor(): void
    {
        // Под этим root нет ни bootstrap/app.php, ни vendor/autoload.php →
        // бутстрап невозможен → обязателен откат к plain .env-экстрактору.
        $lex = require $this->root . '/conf/laravel.php';
        $db = $lex(['root' => sys_get_temp_dir(), 'path' => $this->envFile('DB_DATABASE=novendor'), 'bootstrap' => true]);
        $this->assertSame('novendor', $db['name']);
    }
}
