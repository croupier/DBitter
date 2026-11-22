<?php

/**
 * Юнит-тесты DbTools\Lib\Config: нормализация параметров БД от неполного
 * экстрактора, приоритет CLI над профилем и экстрактором, порядок поиска
 * опций (CLI > profile.dump > profile > default), сбор секретов для
 * маскировки и автоопределение типа конфига проекта. Без БД: используется
 * временный корень с намеренно неполным экстрактором.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Config;
use DbTools\Lib\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет контракт Config, на который опираются все действия:
 * полный набор ключей db() без undefined-index, детерминированный
 * приоритет настроек и корректное автоопределение экстрактора.
 */
final class ConfigTest extends TestCase
{
    /** Временный корень «проекта»; внутри него tool/ играет роль DBitter. */
    private string $base = '';

    /**
     * Готовит изолированный корень и экстрактор conf/partial.php,
     * который возвращает ТОЛЬКО ключ name — худший случай неполного
     * экстрактора, на котором проверяется нормализация дефолтов.
     */
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/dbt_cfg_' . bin2hex(random_bytes(3));
        mkdir($this->base . '/tool/conf', 0775, true);
        // Намеренно частичный экстрактор: есть только "name", остальное отсутствует.
        file_put_contents(
            $this->base . '/tool/conf/partial.php',
            '<?php return static fn(array $o): array => ["name" => "only_name"];'
        );
    }

    /** Удаляет временный корень со всем содержимым после каждого теста. */
    protected function tearDown(): void
    {
        if ($this->base !== '') {
            Fs::rrmdir($this->base);
        }
    }

    /** Путь к «корню инструмента» внутри временного проекта. */
    private function root(): string
    {
        return $this->base . '/tool';
    }

    /**
     * Регрессия undefined-index: частичный экстрактор не должен приводить
     * к отсутствующим ключам в db() — Config обязан дополнить схему
     * дефолтами (host/port/user/pass/charset/prefix/socket/engine/user_host),
     * иначе Doctor, манифест и clientBase падали бы на недостающих ключах.
     */
    public function testResolveDbNormalizesMissingKeys(): void
    {
        $db = (new Config($this->root(), ['conf' => 'partial']))->db();
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
     * Приоритет CLI: --host/--port перекрывают значения экстрактора;
     * строковый порт из argv приводится к int (CLI всегда отдаёт строки).
     */
    public function testCliOverridesExtractorValues(): void
    {
        $db = (new Config($this->root(), ['conf' => 'partial', 'host' => 'db.local', 'port' => '3307']))->db();
        $this->assertSame('db.local', $db['host']);
        $this->assertSame(3307, $db['port']);
    }

    /**
     * Порядок поиска opt(): CLI > profile['dump'][key] > profile[key] > default.
     * kind из CLI перекрывает профиль, compress берётся из profile.dump,
     * отсутствующий ключ отдаёт переданный default.
     */
    public function testOptPrecedenceCliOverProfileDump(): void
    {
        file_put_contents($this->root() . '/config.php', '<?php return ["dump" => ["kind" => "schema", "compress" => "gzip"]];');
        $withCli = new Config($this->root(), ['conf' => 'partial', 'kind' => 'full']);
        $this->assertSame('full', $withCli->opt('kind', 'x'));
        $this->assertSame('gzip', $withCli->opt('compress', 'x')); // откат к profile.dump
        $noCli = new Config($this->root(), ['conf' => 'partial']);
        $this->assertSame('schema', $noCli->opt('kind', 'x'));
        $this->assertSame('fallback', $noCli->opt('absent', 'fallback'));
    }

    /**
     * secrets() собирает только НЕпустые пароли (db.pass и admin.pass) —
     * этот список Command::mask использует для зачистки логов. Пустые
     * значения в список попасть не должны (маскировать '' нельзя).
     */
    public function testSecretsCollectsNonEmptyPasswords(): void
    {
        file_put_contents($this->root() . '/config.php', '<?php return ["admin" => ["user" => "root", "pass" => "topsecret"]];');
        $cfg = new Config($this->root(), ['conf' => 'partial']);
        $this->assertSame(['topsecret'], $cfg->secrets()); // пароль БД пуст, admin-пароль есть
    }

    /**
     * Без настройки dump.dir каталог дампов по умолчанию — <корень>/dump.
     */
    public function testDumpDirDefaultsUnderRoot(): void
    {
        $cfg = new Config($this->root(), ['conf' => 'partial']);
        $this->assertSame($this->root() . '/dump', $cfg->dumpDir());
    }

    /**
     * Автоопределение экстрактора по маркерам проекта: wp-config.php → wp,
     * artisan+.env → laravel, только .env → env, ничего → const.
     * detectConf приватный, поэтому вызывается через рефлексию.
     */
    #[DataProvider('confMarkers')]
    public function testDetectConf(string $marker, string $expected): void
    {
        // detectConf() смотрит на dirname(root) как на каталог проекта.
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
        $this->assertSame($expected, $m->invoke(null, $this->root()));
    }

    /**
     * Набор случаев для testDetectConf: маркер(ы) → ожидаемый экстрактор.
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
