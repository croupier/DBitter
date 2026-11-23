<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Fs;

/**
 * "docker" transport: mysql/mysqldump run inside a container via
 * `docker exec` (by container name) or `docker compose exec` (by service
 * name). The command is delivered by the container shell (bash or sh),
 * env is folded into a bash string — the password never enters docker's argv.
 */
final class Docker extends Transport
{
    /**
     * Transport name for logs and diagnostics.
     *
     * @return string Always 'docker'.
     */
    public function name(): string
    {
        return 'docker';
    }

    /**
     * PDO from the local PHP is not used: the server lives inside the container,
     * and the outward-facing port may not be published. SQL actions go through
     * launch() and the mysql binary in the container.
     *
     * @return bool Always false.
     */
    public function pdoReachable(): bool
    {
        return false;
    }

    /**
     * Builds the launch wrapper: two modes driven by the transport config.
     *
     * 1) service is set — `docker compose [-f compose_file] exec -T <service> <shell> -c`:
     *    convenient when the container is defined in a compose project; compose_file lets
     *    you point at a non-standard file. The -T flag disables the pseudo-TTY (non-interactive
     *    launch, stdin stays free for SQL during import).
     * 2) container is set — `docker exec -i <container> <shell> -c`: direct launch
     *    by container name/ID; -i keeps stdin open for dump import.
     *
     * The shell is configured via the transport.shell key: some MySQL images
     * ship without bash, for which you set 'sh'.
     *
     * @return list<string> Wrapper arguments; '-c' comes last, and launch() appends the bash string after it.
     * @throws \RuntimeException If neither container nor service is set.
     */
    private function launcher(): array
    {
        $docker = (string) ($this->tconfig['docker'] ?? 'docker');
        // Некоторые MySQL-образы идут без bash; оболочку можно задать в конфиге транспорта (например, sh).
        $shell = (string) ($this->tconfig['shell'] ?? 'bash');
        if (!empty($this->tconfig['service'])) {
            $cmd = [$docker, 'compose'];
            if (!empty($this->tconfig['compose_file'])) {
                $cmd[] = '-f';
                $cmd[] = (string) $this->tconfig['compose_file'];
            }
            $cmd[] = 'exec';
            $cmd[] = '-T';
            $cmd[] = (string) $this->tconfig['service'];
            $cmd[] = $shell;
            $cmd[] = '-c';
            return $cmd;
        }
        $container = (string) ($this->tconfig['container'] ?? '');
        if ($container === '') {
            throw new \RuntimeException('docker transport needs "container" or "service" in config');
        }
        return [$docker, 'exec', '-i', $container, $shell, '-c'];
    }

    /**
     * Wraps the target-side command into a launch inside the container.
     * Env (including MYSQL_PWD) is folded into the `<shell> -c` string: local
     * process environment variables do not cross the docker exec boundary, so
     * the password is passed as an assignment inside the bash string, not in docker's argv.
     *
     * @param list<string> $argv Command arguments inside the container.
     * @param array<string,string> $env Environment variables for the command in the container.
     * @return array{cmd:string|list<string>,env:array<string,string>} Array command for proc_open; env is empty.
     */
    public function launch(array $argv, array $env = []): array
    {
        return $this->viaBash($this->launcher(), $argv, $env);
    }

    /**
     * Diagnostics: presence of the docker binary on the local machine, then a
     * `mysql --version` probe inside the container — which also verifies that
     * the container is running and the exec wrapper works.
     *
     * @return array{ok:bool,info:string} ok — transport is ready; info — the mysql version or the error text.
     */
    public function doctor(): array
    {
        if (!Fs::hasBinary((string) ($this->tconfig['docker'] ?? 'docker'))) {
            return ['ok' => false, 'info' => 'docker binary not found'];
        }
        $probe = $this->versionProbe($this->mysqlBin());
        return ['ok' => $probe['ok'], 'info' => $probe['info']];
    }
}
