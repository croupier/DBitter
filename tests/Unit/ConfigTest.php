<?php

/**
 * Unit tests for DbTools\Lib\Config: normalizing DB parameters from an
 * incomplete extractor, CLI precedence over the profile and the extractor,
 * the opt() lookup order (CLI > profile.dump > profile > default), collecting
 * secrets for masking, and auto-detecting the project config type. No DB
 * involved: a temporary root with a deliberately incomplete extractor is used.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Config;
use DbTools\Lib\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Config contract all actions rely on:
 * a complete key set from db() without undefined-index errors, deterministic
 * settings precedence, and correct extractor auto-detection.
 */
final class ConfigTest extends TestCase
{
    /** Temporary "project" root; tool/ inside it plays the role of DBitter. */
    private string $base = '';

    /**
     * Prepares an isolated root and a conf/partial.php extractor
     * that returns ONLY the name key — the worst-case incomplete extractor,
     * used to verify default normalization. The .git directory in base acts
     * as the discovery boundary: no project markers exist, the upward walk
     * stops there, and Config runs in the legacy fallback
     * (project = dirname(tool)), exactly as these tests expect.
     */
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/dbt_cfg_' . bin2hex(random_bytes(3));
        mkdir($this->base . '/tool/conf', 0775, true);
        mkdir($this->base . '/.git');
        // Намеренно частичный экстрактор: есть только "name", остальное отсутствует.
        file_put_contents(
            $this->base . '/tool/conf/partial.php',
            '<?php return static fn(array $o): array => ["name" => "only_name"];'
        );
    }

    /** Removes the temporary root with all its contents after each test. */
    protected function tearDown(): void
    {
        if ($this->base !== '') {
            Fs::rrmdir($this->base);
        }
    }

    /** Path to the "tool root" inside the temporary project. */
    private function root(): string
    {
        return $this->base . '/tool';
    }

    /**
     * undefined-index regression: a partial extractor must not leave missing
     * keys in db() — Config has to fill the schema up with defaults
     * (host/port/user/pass/charset/prefix/socket/engine/user_host), otherwise
     * Doctor, the manifest and clientBase would crash on the missing keys.
     */
    public function testResolveDbNormalizesMissingKeys(): void
    {
        $db = (new Config($this->root(), ['conf' => 'partial'], $this->base))->db();
        $this->assertSame('only_name', $db['name']);
        $this->assertSame('127.0.0.1', $db['host']);
        $this->assertSame(3306, $db['port']);
        $this->assertSame('root', $db['user']);
        $this->assertSame('', $db['pass']);
        $this->assertSame('utf8mb4', $db['charset']);
        $this->assertSame('', $db['prefix']);
        $this->assertNull($db['socket']);
        $this->assertSame('mysql', $db['engine']);
        $this->assertSame('localhost', $db['user_host']);
    }

    /**
     * CLI precedence: --host/--port override the extractor values;
     * a string port from argv is cast to int (CLI always yields strings).
     */
    public function testCliOverridesExtractorValues(): void
    {
        $db = (new Config($this->root(), ['conf' => 'partial', 'host' => 'db.local', 'port' => '3307'], $this->base))->db();
        $this->assertSame('db.local', $db['host']);
        $this->assertSame(3307, $db['port']);
    }

    /**
     * opt() lookup order: CLI > profile['dump'][key] > profile[key] > default.
     * kind from CLI overrides the profile, compress comes from profile.dump,
     * a missing key returns the default that was passed in.
     */
    public function testOptPrecedenceCliOverProfileDump(): void
    {
        file_put_contents($this->root() . '/config.php', '<?php return ["dump" => ["kind" => "schema", "compress" => "gzip"]];');
        $withCli = new Config($this->root(), ['conf' => 'partial', 'kind' => 'full'], $this->base);
        $this->assertSame('full', $withCli->opt('kind', 'x'));
        $this->assertSame('gzip', $withCli->opt('compress', 'x')); // откат к profile.dump
        $noCli = new Config($this->root(), ['conf' => 'partial'], $this->base);
        $this->assertSame('schema', $noCli->opt('kind', 'x'));
        $this->assertSame('fallback', $noCli->opt('absent', 'fallback'));
    }

    /**
     * secrets() collects only NON-empty passwords (db.pass and admin.pass) —
     * Command::mask uses this list to scrub logs. Empty values must not end up
     * in the list (an empty string '' must never be masked).
     */
    public function testSecretsCollectsNonEmptyPasswords(): void
    {
        file_put_contents($this->root() . '/config.php', '<?php return ["admin" => ["user" => "root", "pass" => "topsecret"]];');
        $cfg = new Config($this->root(), ['conf' => 'partial'], $this->base);
        $this->assertSame(['topsecret'], $cfg->secrets()); // пароль БД пуст, admin-пароль есть
    }

    /**
     * With no dump.dir setting and no project found from cwd (legacy fallback),
     * the default dump directory is <tool root>/dump.
     */
    public function testDumpDirDefaultsUnderRoot(): void
    {
        $cfg = new Config($this->root(), ['conf' => 'partial'], $this->base);
        $this->assertSame($this->root() . '/dump', $cfg->dumpDir());
    }

    /**
     * Extractor auto-detection via project markers: wp-config.php → wp,
     * artisan+.env → laravel, only .env → env, nothing → const.
     * detectConf is private, so it is invoked through reflection.
     */
    #[DataProvider('confMarkers')]
    public function testDetectConf(string $marker, string $expected): void
    {
        // detectConf() принимает корень проекта напрямую (discovery или фолбэк).
        $project = $this->base;
        // Чистим маркеры предыдущего набора данных (провайдер запускает
        // несколько тестов подряд в одном и том же временном проекте).
        foreach (['wp-config.php', 'artisan', '.env'] as $f) {
            @unlink($project . '/' . $f);
        }
        if ($marker === 'laravel') {
            touch($project . '/artisan');
            touch($project . '/.env');
        } elseif ($marker !== 'none') {
            touch($project . '/' . $marker);
        }
        $m = new \ReflectionMethod(Config::class, 'detectConf');
        $this->assertSame($expected, $m->invoke(null, $project));
    }

    /**
     * Set of cases for testDetectConf: marker(s) → expected extractor.
     *
     * @return array<string,array{string,string}>
     */
    public static function confMarkers(): array
    {
        return [
            'wordpress' => ['wp-config.php', 'wp'],
            'laravel' => ['laravel', 'laravel'],
            'dotenv' => ['.env', 'env'],
            'none' => ['none', 'const'],
        ];
    }
}
