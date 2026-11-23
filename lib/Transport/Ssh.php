<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Fs;

/**
 * "ssh" transport: mysql/mysqldump run on a remote host via an
 * SSH client (ssh by default; the binary is configurable — you can point at a
 * different client such as plink, or a full path to ssh.exe). The command is
 * delivered by the remote shell via `bash -c`, env is folded into that string.
 */
final class Ssh extends Transport
{
    /**
     * Transport name for logs and diagnostics.
     *
     * @return string Always 'ssh'.
     */
    public function name(): string
    {
        return 'ssh';
    }

    /**
     * The user@host destination string for the SSH client.
     * User defaults to root; host is required — without it connecting is
     * impossible, so a clear exception is thrown right away.
     *
     * @return string A destination of the form 'user@host'.
     * @throws \RuntimeException If host is not set in the transport config.
     */
    private function destination(): string
    {
        $user = (string) ($this->tconfig['user'] ?? 'root');
        $host = (string) ($this->tconfig['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('ssh transport needs "host" in config');
        }
        return $user . '@' . $host;
    }

    /**
     * Builds the SSH client argv: `<ssh> -T -p <port> [-i key] [-o option ...] user@host`.
     *
     * -T forbids allocating a pseudo-TTY: the launch is non-interactive, stdin
     * stays a data channel (SQL during import). Default port is 22.
     * The key (-i) is for key-based auth instead of a password. Arbitrary options from
     * transport.options are passed as `-o <option>` (OpenSSH syntax):
     * this is how you set, for example, StrictHostKeyChecking=no for the first
     * connection to a new host without interactive fingerprint confirmation.
     *
     * @return list<string> ssh client arguments; the command's bash string is appended in launch().
     * @throws \RuntimeException If host is not set (via destination()).
     */
    private function launcher(): array
    {
        $ssh = (string) ($this->tconfig['ssh'] ?? 'ssh');
        $cmd = [$ssh, '-T', '-p', (string) ($this->tconfig['port'] ?? 22)];
        if (!empty($this->tconfig['key'])) {
            $cmd[] = '-i';
            $cmd[] = (string) $this->tconfig['key'];
        }
        foreach ($this->tconfig['options'] ?? [] as $option) {
            $cmd[] = '-o';
            $cmd[] = (string) $option;
        }
        $cmd[] = $this->destination();
        return $cmd;
    }

    /**
     * Wraps the target-side command into a remote launch over ssh.
     *
     * "Password folding": env (including MYSQL_PWD) becomes
     * KEY='value' assignments inside the `bash -c` string (viaBash). The password
     * enters neither ssh's argv nor the mysql arguments on the remote side
     * and travels only inside the encrypted SSH channel; in logs the string
     * is masked (Command::mask).
     *
     * @param list<string> $argv Command arguments on the remote host.
     * @param array<string,string> $env Environment variables folded into the remote bash string.
     * @return array{cmd:string|list<string>,env:array<string,string>} Array command for proc_open; env is empty.
     * @throws \RuntimeException If host is not set.
     */
    public function launch(array $argv, array $env = []): array
    {
        return $this->viaBash($this->launcher(), $argv, $env);
    }

    /**
     * PDO from the local PHP is not used: the server sits on a remote
     * host and may be closed off by a firewall. SQL actions run via
     * launch() and the mysql binary on the remote side.
     *
     * @return bool Always false.
     */
    public function pdoReachable(): bool
    {
        return false;
    }

    /**
     * Diagnostics: presence of the SSH client locally, then a `mysql --version`
     * probe on the remote host — which also checks connectivity, authentication
     * and mysql availability, i.e. the entire path a real command takes.
     *
     * @return array{ok:bool,info:string} ok — transport is ready; info — the mysql version or the error text.
     */
    public function doctor(): array
    {
        if (!Fs::hasBinary((string) ($this->tconfig['ssh'] ?? 'ssh'))) {
            return ['ok' => false, 'info' => 'ssh binary not found'];
        }
        $probe = $this->versionProbe($this->mysqlBin());
        return ['ok' => $probe['ok'], 'info' => $probe['info']];
    }
}
