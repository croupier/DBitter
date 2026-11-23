<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Fs;

/**
 * DBitter's base abstract transport.
 *
 * A transport defines HOW the mysql/mysqldump binaries are launched on the
 * target side: local (directly), wsl (wsl.exe), docker (docker exec), ssh (remotely),
 * pdo (no binaries). This class gathers the make() factory, shared helpers for
 * subclasses (command pass-through, launching via bash, version probing) and
 * default values for binary names taken from the transport configuration.
 */
abstract class Transport
{
    /** @var Config Shared tool configuration: profile, DB credentials, CLI options. */
    protected Config $cfg;

    /** @var array<string,mixed> Current transport settings — the profile.transport[target] section. */
    protected array $tconfig;

    /**
     * Stores the configuration and immediately extracts the transport settings
     * section so that subclasses take parameters (distro, container, host, etc.)
     * from the ready-made $tconfig array instead of querying Config again.
     *
     * @param Config $cfg DBitter configuration (profile + CLI options).
     */
    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->tconfig = $cfg->transportConfig();
    }

    /**
     * Transport name ('local', 'wsl', 'docker', 'ssh', 'pdo').
     * Used in logs and diagnostics to identify the target side.
     *
     * @return string Short transport name.
     */
    abstract public function name(): string;

    /**
     * Wraps the target-side argv (+env) into a command for proc_open.
     *
     * Each subclass decides how to deliver the command to mysql/mysqldump:
     * directly (Local), via wsl.exe/a bash script (Wsl), via docker exec
     * or ssh (Docker/Ssh). For bash-oriented targets the env variables are
     * "folded" into the remote command: proc_open env does not cross the
     * ssh/docker boundary, so MYSQL_PWD and other values are passed as
     * KEY='value' assignments right before the command itself.
     *
     * @param list<string> $argv Command arguments on the target side (e.g. ['mysqldump', 'db']).
     * @param array<string,string> $env Environment variables for the target process (incl. MYSQL_PWD).
     * @return array{cmd:string|list<string>,env:array<string,string>} Command and env for Command::run/capture.
     */
    abstract public function launch(array $argv, array $env = []): array;

    /**
     * Transport self-check: verifies the availability of the wrapper (wsl.exe,
     * docker, ssh, the pdo_mysql extension) and that mysql works on the target.
     *
     * @return array{ok:bool,info:string} ok — whether everything is ready to work; info — a human-readable detail.
     */
    abstract public function doctor(): array;

    /**
     * Name/path of the mysql binary on the target side.
     * Allows overriding the name in the transport config (e.g. mariadb
     * or an absolute path); defaults to plain 'mysql' from the target's PATH.
     *
     * @return string The transport.mysql value or 'mysql'.
     */
    public function mysqlBin(): string
    {
        return (string) ($this->tconfig['mysql'] ?? 'mysql');
    }

    /**
     * Name/path of the mysqldump binary on the target side.
     * Like mysqlBin(): overridden by the transport.mysqldump key.
     *
     * @return string The transport.mysqldump value or 'mysqldump'.
     */
    public function mysqldumpBin(): string
    {
        return (string) ($this->tconfig['mysqldump'] ?? 'mysqldump');
    }

    /**
     * Whether SQL actions (create-db/create-user/drop-db) may use a PDO
     * connection straight from the local PHP, bypassing the mysql binary.
     *
     * Defaults to true (Local/Pdo). Transports where the server lives inside
     * an isolated environment (WSL, container, remote host) return
     * false: SQL runs the same way as dumps — via launch().
     *
     * @return bool true if a local PDO connection is guaranteed to reach the server.
     */
    public function pdoReachable(): bool
    {
        return true;
    }

    /**
     * Factory: creates a transport from the target value in the CLI/profile.
     * Single selection point — calling code knows nothing about the classes.
     *
     * @param Config $cfg Configuration; target is taken from $cfg->target().
     * @return self An instance of Local, Wsl, Docker, Ssh or Pdo.
     * @throws \RuntimeException If the target is unrecognized (typo in config/CLI).
     */
    public static function make(Config $cfg): self
    {
        return match ($cfg->target()) {
            'local' => new Local($cfg),
            'wsl' => new Wsl($cfg),
            'docker' => new Docker($cfg),
            'ssh' => new Ssh($cfg),
            'pdo' => new Pdo($cfg),
            default => throw new \RuntimeException('Unknown target: ' . $cfg->target()),
        };
    }

    /**
     * Passes the command through unchanged: argv stays an array, env a separate
     * array. The proc_open array form on Windows escapes process arguments
     * correctly on its own, and env is handed to the process directly — the
     * password (MYSQL_PWD) ends up neither in the command line nor in mysql's argv.
     *
     * @param list<string> $argv Command arguments.
     * @param array<string,string> $env Environment variables.
     * @return array{cmd:string|list<string>,env:array<string,string>} Pair for Command::run/capture.
     */
    protected function passthrough(array $argv, array $env): array
    {
        return ['cmd' => $argv, 'env' => $env];
    }

    /**
     * Launch via bash for ssh/docker (proc_open array form).
     *
     * Env is folded into the remote command string (`KEY='val' ... cmd args`,
     * see Command::bashString): otherwise the variables would stay on the local
     * side and never reach mysql inside the container/on the host. The password
     * is thus passed as MYSQL_PWD inside the bash string, not as a mysql argument.
     * ssh.exe/docker.exe parse quoted array elements correctly, unlike wsl.exe —
     * which is why Wsl uses a separate launch via a script file.
     *
     * @param list<string> $launcher Launch wrapper, e.g. ['ssh', ..., 'user@host'] or ['docker', 'exec', ...].
     * @param list<string> $argv Command arguments on the target side.
     * @param array<string,string> $env Environment variables folded into the bash string.
     * @return array{cmd:string|list<string>,env:array<string,string>} cmd — array with the bash string as last element; env is empty.
     */
    protected function viaBash(array $launcher, array $argv, array $env): array
    {
        $launcher[] = Command::bashString($argv, $env);
        return ['cmd' => $launcher, 'env' => []];
    }

    /**
     * Verifies a binary on the target side by running `<bin> --version`
     * through launch() — that is, the same path real commands will take.
     * This also exercises the whole transport wrapper (wsl/docker/ssh),
     * not just the existence of the file.
     *
     * @param string $bin Binary name (mysql/mysqldump).
     * @return array{ok:bool,info:string} ok — exit code 0; info — stdout on success, otherwise the full output (including stderr).
     */
    protected function versionProbe(string $bin): array
    {
        $launch = $this->launch([$bin, '--version']);
        $res = Command::capture($launch['cmd'], $launch['env']);
        return [
            'ok' => $res->ok(),
            'info' => $res->ok() ? trim($res->stdout) : trim($res->output()),
        ];
    }

    /**
     * Checks whether a binary exists on the LOCAL machine: lookup via PATH
     * (Fs::hasBinary) or a direct file path — the config may specify an
     * absolute path instead of a name.
     *
     * @param string $bin A PATH name or a path to the file.
     * @return bool true if the binary was found.
     */
    protected function localBinaryCheck(string $bin): bool
    {
        return Fs::hasBinary($bin) || is_file($bin);
    }
}
