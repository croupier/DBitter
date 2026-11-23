<?php

/**
 * Unit tests for the transport launch wrappers (DbTools\Transport\*): only
 * command assembly, NO execution — they run on any host without a DB.
 * Key invariant: the bash transports (ssh/docker/wsl) embed MYSQL_PWD into
 * the command on the target side and pass an EMPTY env array to proc_open,
 * while local hands the password over as a real environment variable.
 * Plus the WSL specifics on Windows: the secret lives in a script file, not in argv.
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
 * Pure "launch-wrapping" tests: they check the shape of the command and env
 * the transport would return for proc_open. Nothing is executed, so the run
 * requires neither MySQL nor ssh/docker/wsl on the machine.
 */
final class TransportLaunchTest extends TestCase
{
    /**
     * The Transport::make factory returns the right class per target key;
     * for docker/ssh the required fields (container/host) are set in the
     * transport config — without them the factory could not build the transport.
     */
    public function testMakeResolvesEachTarget(): void
    {
        $this->assertInstanceOf(Local::class, Transport::make($this->cfg('local')));
        $this->assertInstanceOf(Wsl::class, Transport::make($this->cfg('wsl')));
        $this->assertInstanceOf(Docker::class, Transport::make($this->cfg('docker', ['container' => 'c'])));
        $this->assertInstanceOf(Ssh::class, Transport::make($this->cfg('ssh', ['host' => 'h'])));
    }

    /**
     * local — transparent pass-through: argv is unchanged, MYSQL_PWD stays a
     * real env array for proc_open (the password is not in argv).
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
     * ssh "folds" the credentials into the command on the remote side:
     * MYSQL_PWD='pw' travels inside the bash string over SSH, while the env
     * passed to proc_open is empty — the local password never shows up in the
     * launcher process's environment. Checks user@host, the -p port, and the
     * MYSQL_PWD='...' form.
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
     * docker respects the container's configured shell: shell=sh →
     * `docker exec -i <c> sh -c ...` (minimal images may lack bash),
     * and the env for proc_open is empty.
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
     * Without an explicit shell, docker defaults to bash —
     * the default must not change silently.
     */
    public function testDockerDefaultsToBash(): void
    {
        $t = Transport::make($this->cfg('docker', ['container' => 'mysql1']));
        $inner = implode(' ', $t->launch(['mysql'], ['MYSQL_PWD' => 'pw'])['cmd']);
        $this->assertStringContainsString('exec -i mysql1 bash -c', $inner);
    }

    /**
     * WSL: the password NEVER leaks through the Windows process environment
     * (env is empty), and PDO from the host PHP is unavailable (pdoReachable=false —
     * the server lives inside WSL). On Windows the command runs via a script file
     * .dbt_wsl_cmd_*.sh: the secret is inside the file, not in argv
     * (argv is visible in the process list); on other hosts it is inlined via bash -c.
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
     * Helper: a Config on the deterministic const_db.php fixture with the
     * requested target. The transport.<target> section is substituted via
     * reflection (the profile property is private) — this lets the test set
     * transport parameters without depending on the real machine's
     * config.php/config.local.php.
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
