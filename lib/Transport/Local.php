<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Command;
use DbTools\Lib\Fs;

/**
 * "local" transport: mysql/mysqldump are run directly on this same machine
 * (e.g. binaries from PATH or from a Herd installation). The simplest transport —
 * no wrapper at all, argv and env are passed to the process as-is.
 */
final class Local extends Transport
{
    /**
     * Transport name for logs and diagnostics.
     *
     * @return string Always 'local'.
     */
    public function name(): string
    {
        return 'local';
    }

    /**
     * Passes the command through with no wrappers: the proc_open array form
     * escapes arguments correctly on Windows by itself, and env (including
     * MYSQL_PWD) is handed to the process separately — so the password ends
     * up neither in the command line nor in the mysql/mysqldump argv.
     *
     * @param list<string> $argv Command arguments, e.g. ['mysqldump', 'db'].
     * @param array<string,string> $env Environment variables (MYSQL_PWD etc.).
     * @return array{cmd:string|list<string>,env:array<string,string>} cmd — the original argv array; env — the original env.
     */
    public function launch(array $argv, array $env = []): array
    {
        return $this->passthrough($argv, $env);
    }

    /**
     * Diagnostics: checks that both binaries exist (via PATH or a direct path
     * from the config), then actually runs `mysql --version` — this confirms
     * that the binary is not only found but also executable.
     *
     * @return array{ok:bool,info:string} ok — check succeeded; info — the mysql version or the error text.
     */
    public function doctor(): array
    {
        $mysql = $this->mysqlBin();
        $dump = $this->mysqldumpBin();
        // Сначала проверяем наличие файлов, чтобы дать понятную ошибку
        // «binary not found» вместо невнятного сбоя proc_open.
        if (!$this->localBinaryCheck($mysql)) {
            return ['ok' => false, 'info' => "mysql binary not found: {$mysql}"];
        }
        if (!$this->localBinaryCheck($dump)) {
            return ['ok' => false, 'info' => "mysqldump binary not found: {$dump}"];
        }
        $res = Command::capture([$mysql, '--version']);
        return ['ok' => $res->ok(), 'info' => trim($res->stdout ?: $res->output())];
    }
}
