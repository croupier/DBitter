<?php

/**
 * Юнит-тесты обёрток запуска транспортов (DbTools\Transport\*): только
 * сборка команды, НИКАКОГО исполнения — работают на любом хосте без БД.
 * Ключевой инвариант: bash-транспорты (ssh/docker/wsl) встраивают
 * MYSQL_PWD в команду на целевой стороне и передают proc_open ПУСТОЙ
 * env-массив, а local отдаёт пароль настоящей переменной окружения.
 * Плюс специфика WSL на Windows: секрет живёт в скрипт-файле, не в argv.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Config;
use DbTools\Lib\Fs;
use DbTools\Transport\Docker;
use DbTools\Transport\Local;
use DbTools\Transport\Ssh;
use DbTools\Transport\Transport;
use DbTools\Transport\Wsl;
use PHPUnit\Framework\TestCase;

/**
 * Чисто «launch-wrapping» тесты: проверяют форму команды и env, которые
 * транспорт вернул бы для proc_open. Ничего не запускается, поэтому
 * прогон не требует ни MySQL, ни ssh/docker/wsl на машине.
 */
final class TransportLaunchTest extends TestCase
{
    /**
     * Фабрика Transport::make по ключу target возвращает правильный класс;
     * для docker/ssh обязательные поля (container/host) задаются в конфиге
     * транспорта — без них фабрика не смогла бы построить транспорт.
     */
    public function testMakeResolvesEachTarget(): void
    {
        $this->assertInstanceOf(Local::class, Transport::make($this->cfg('local')));
        $this->assertInstanceOf(Wsl::class, Transport::make($this->cfg('wsl')));
        $this->assertInstanceOf(Docker::class, Transport::make($this->cfg('docker', ['container' => 'c'])));
        $this->assertInstanceOf(Ssh::class, Transport::make($this->cfg('ssh', ['host' => 'h'])));
    }

    /**
     * local — прозрачная передача: argv не меняется, MYSQL_PWD остаётся
     * настоящим env-массивом для proc_open (пароль не в argv).
     */
    public function testLocalPassthroughKeepsEnvArray(): void
    {
        $t = Transport::make($this->cfg('local'));
        $this->assertSame('local', $t->name());
        $launch = $t->launch(['mysql', '--user=x'], ['MYSQL_PWD' => 'pw']);
        $this->assertSame(['mysql', '--user=x'], $launch['cmd']);
        $this->assertSame(['MYSQL_PWD' => 'pw'], $launch['env']);
    }

    /**
     * ssh «складывает» учётные данные в команду на удалённой стороне:
     * MYSQL_PWD='pw' едет внутри bash-строки по SSH, а env для proc_open
     * пуст — локальный пароль не светится в окружении процесса-лаунчера.
     * Проверяются user@host, порт -p и форма MYSQL_PWD='...'.
     */
    public function testSshFoldsCredentialsIntoCommandAndEmptiesEnv(): void
    {
        $t = Transport::make($this->cfg('ssh', ['host' => 'db.example', 'user' => 'admin', 'port' => 2222]));
        $this->assertSame('ssh', $t->name());
        $launch = $t->launch(['mysqldump', 'db'], ['MYSQL_PWD' => 'pw']);
        $this->assertSame([], $launch['env']);
        $inner = implode(' ', $launch['cmd']);
        $this->assertStringContainsString('admin@db.example', $inner);
        $this->assertStringContainsString('-p 2222', $inner);
        $this->assertStringContainsString("MYSQL_PWD='pw'", $inner);
    }

    /**
     * docker уважает настроенную оболочку контейнера: shell=sh →
     * `docker exec -i <c> sh -c ...` (в минимальных образах bash может
     * отсутствовать), env для proc_open пуст.
     */
    public function testDockerUsesConfiguredShell(): void
    {
        $t = Transport::make($this->cfg('docker', ['container' => 'mysql1', 'shell' => 'sh']));
        $launch = $t->launch(['mysql'], ['MYSQL_PWD' => 'pw']);
        $inner = implode(' ', $launch['cmd']);
        $this->assertStringContainsString('exec -i mysql1 sh -c', $inner);
        $this->assertStringNotContainsString('bash', $inner);
        $this->assertSame([], $launch['env']);
    }

    /**
     * Без явного shell docker по умолчанию использует bash —
     * дефолт не должен молча меняться.
     */
    public function testDockerDefaultsToBash(): void
    {
        $t = Transport::make($this->cfg('docker', ['container' => 'mysql1']));
        $inner = implode(' ', $t->launch(['mysql'], ['MYSQL_PWD' => 'pw'])['cmd']);
        $this->assertStringContainsString('exec -i mysql1 bash -c', $inner);
    }

    /**
     * WSL: пароль НИКОГДА не утекает через окружение Windows-процесса
     * (env пуст) и PDO из хостового PHP недоступен (pdoReachable=false —
     * сервер живёт внутри WSL). На Windows команда выполняется через
     * скрипт-файл .dbt_wsl_cmd_*.sh: секрет внутри файла, а не в argv
     * (argv виден в списке процессов); на прочих хостах — инлайн bash -c.
     */
    public function testWslNeverLeaksPasswordViaProcEnv(): void
    {
        $t = Transport::make($this->cfg('wsl', ['distro' => 'Ubuntu', 'user' => 'root']));
        $this->assertSame('wsl', $t->name());
        $this->assertFalse($t->pdoReachable());

        $launch = $t->launch(['mysqldump', 'db'], ['MYSQL_PWD' => 'pw']);
        $this->assertSame([], $launch['env']);            // не переменная окружения Windows/процесса
        $inner = is_array($launch['cmd']) ? implode(' ', $launch['cmd']) : $launch['cmd'];
        $this->assertStringContainsString('bash', $inner);
        // На Windows запускается скрипт-файл, поэтому секрет живёт ВНУТРИ
        // файла (не в argv); на других хостах он инлайнится через bash -c.
        if (Fs::isWindows()) {
            $this->assertStringNotContainsString('pw', $inner);
            $this->assertStringContainsString('.dbt_wsl_cmd_', $inner);
        } else {
            $this->assertStringContainsString("MYSQL_PWD='pw'", $inner);
        }
    }

    /**
     * Хелпер: Config на детерминированной фикстуре const_db.php с нужным
     * target. Секция transport.<target> подменяется через рефлексию
     * (свойство profile приватное) — так тест задаёт параметры транспорта,
     * не завися от config.php/config.local.php реальной машины.
     *
     * @param array<string,mixed> $transportConfig
     */
    private function cfg(string $target, array $transportConfig = []): Config
    {
        $cfg = new Config(dirname(__DIR__, 2), [
            'conf' => 'const',
            'config' => __DIR__ . '/../_data/const_db.php',
            'target' => $target,
        ]);
        $ref = new \ReflectionProperty(Config::class, 'profile');
        /** @var array<string,mixed> $profile */
        $profile = $ref->getValue($cfg);
        $profile['transport'][$target] = $transportConfig;
        $ref->setValue($cfg, $profile);
        return $cfg;
    }
}
