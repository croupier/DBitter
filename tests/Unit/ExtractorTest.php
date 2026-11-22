<?php

/**
 * Юнит-тесты экстракторов параметров БД из конфигов приложения
 * (conf/env.php и conf/laravel.php). Проверяют разбор DATABASE_URL
 * (mysql/pgsql), классические ключи DB_*, дефолтный порт, ошибку при
 * отсутствии имени БД и откат Laravel-экстрактора к plain .env, когда
 * бутстрап приложения невозможен. Без БД — на временных .env-файлах.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Экстракторы — замыкания, возвращаемые файлами conf/*.php; здесь они
 * вызываются напрямую с массивом опций (root/path/bootstrap), как это
 * делает Config::resolveDb.
 */
final class ExtractorTest extends TestCase
{
    /** Корень инструмента (DBitter) — от него грузятся conf/*.php. */
    private string $root;

    /** Список временных .env-файлов, созданных тестом (для уборки). */
    private array $files = [];

    /** Вычисляет корень инструмента относительно каталога тестов. */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** Удаляет все временные .env-файлы теста. */
    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    /**
     * Хелпер: создаёт временный .env с заданным содержимым и запоминает
     * его для удаления в tearDown.
     *
     * @return string путь к созданному файлу
     */
    private function envFile(string $content): string
    {
        $p = sys_get_temp_dir() . '/dbt_ex_' . bin2hex(random_bytes(3)) . '.env';
        file_put_contents($p, $content);
        $this->files[] = $p;
        return $p;
    }

    /** Загружает замыкание-экстрактор conf/env.php. */
    private function envExtractor(): \Closure
    {
        return require $this->root . '/conf/env.php';
    }

    /**
     * DATABASE_URL=mysql://user:pass@host:port/db разбирается на все
     * компоненты: engine/host/port(int)/user/pass/name. Формат типичен
     * для PaaS и docker-окружений.
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
     * Схема postgres:// в DATABASE_URL распознаётся как engine=pgsql —
     * инструмент должен явно сообщить о не-MySQL движке, а не молча
     * пытаться подключиться mysql-клиентом.
     */
    public function testEnvDatabaseUrlPostgresDetected(): void
    {
        $ex = $this->envExtractor();
        $db = $ex(['root' => sys_get_temp_dir(), 'path' => $this->envFile('DATABASE_URL=postgres://u:pw@h:5432/pgdb'), 'bootstrap' => false]);
        $this->assertSame('pgsql', $db['engine']);
        $this->assertSame('pgdb', $db['name']);
    }

    /**
     * Классические ключи Laravel-стиля DB_CONNECTION/DB_DATABASE/...
     * читаются напрямую; без DB_PORT подставляется дефолт MySQL 3306.
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
     * Без имени БД экстрактор обязан бросить RuntimeException —
     * молча выгружать «непонятно что» нельзя (лучше явный отказ).
     */
    public function testEnvThrowsWithoutDatabaseName(): void
    {
        $ex = $this->envExtractor();
        $this->expectException(\RuntimeException::class);
        $ex(['root' => sys_get_temp_dir(), 'path' => $this->envFile("DB_HOST=h\n"), 'bootstrap' => false]);
    }

    /**
     * Laravel-экстрактор при bootstrap=false не пытается загружать
     * приложение и сразу читает .env через env-экстрактор.
     */
    public function testLaravelFallsBackToEnvWhenBootstrapDisabled(): void
    {
        $lex = require $this->root . '/conf/laravel.php';
        $db = $lex(['root' => sys_get_temp_dir(), 'path' => $this->envFile('DATABASE_URL=mysql://u:pw@h:3306/lar'), 'bootstrap' => false]);
        $this->assertSame('lar', $db['name']);
        $this->assertSame('mysql', $db['engine']);
    }

    /**
     * Даже с bootstrap=true, если под root нет bootstrap/app.php или
     * vendor/autoload.php (приложение не установлено), бутстрап невозможен —
     * экстрактор обязан откатиться к разбору plain .env, а не упасть.
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
