<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Fs;

/**
 * "wsl" transport: mysql/mysqldump run inside a WSL distro
 * (Ubuntu by default) via wsl.exe. On Windows the command is delivered
 * as a bash script in a temp file rather than a `bash -c` string — this works
 * around wsl.exe/cmd.exe escaping problems and preserves stdin and the exit code.
 */
final class Wsl extends Transport
{
    /** @var string Path to the temporary bash script holding the current command (unique per process). */
    private string $scriptFile;

    /**
     * Prepares the temp script path and registers its removal on PHP
     * shutdown: the script contains MYSQL_PWD, so the file must not
     * stay on disk after the run (even on a fatal error).
     *
     * @param Config $cfg DBitter configuration.
     */
    public function __construct(Config $cfg)
    {
        parent::__construct($cfg);
        // Суффикс с PID процесса: параллельные запуски DBitter не перезапишут
        // скрипт друг друга.
        $this->scriptFile = Fs::tempDir() . DIRECTORY_SEPARATOR . '.dbt_wsl_cmd_' . getmypid() . '.sh';
        register_shutdown_function(function (): void {
            @unlink($this->scriptFile);
        });
    }

    /**
     * Finds the wsl executable: first wsl.exe, then wsl in PATH;
     * if the lookup fails — returns 'wsl.exe' in the hope that the system
     * resolves the name itself at launch time.
     *
     * @return string Path to wsl.exe or the bare name 'wsl.exe'.
     */
    private function wslBin(): string
    {
        return Fs::which('wsl.exe') ?? Fs::which('wsl') ?? 'wsl.exe';
    }

    /**
     * WSL distro name from the transport config (transport.distro).
     *
     * @return string The distro; 'Ubuntu' by default.
     */
    private function distro(): string
    {
        return (string) ($this->tconfig['distro'] ?? 'Ubuntu');
    }

    /**
     * WSL user the command runs as (transport.user).
     * Defaults to root: this guarantees access to mysql/mysqldump,
     * the MySQL socket and dump files under /mnt/<disk>/... without permission issues.
     *
     * @return string Username; 'root' by default.
     */
    private function user(): string
    {
        return (string) ($this->tconfig['user'] ?? 'root');
    }

    /**
     * Transport name for logs and diagnostics.
     *
     * @return string Always 'wsl'.
     */
    public function name(): string
    {
        return 'wsl';
    }

    /**
     * PDO from the local (Windows) PHP is not used for this transport:
     * the server lives inside WSL, so SQL actions take the same path as dumps,
     * via launch() and the mysql binary in the distro.
     *
     * @return bool Always false.
     */
    public function pdoReachable(): bool
    {
        return false;
    }

    /**
     * Wraps the target-side command into a launch through wsl.exe + bash.
     *
     * On Windows — the command is written to a temp script file and run as
     * `bash <path>`; on non-Windows — a regular array launch `bash -c`.
     * Env (including MYSQL_PWD) is folded into the bash string/script, so the
     * password never enters the argv of wsl.exe itself.
     *
     * @param list<string> $argv Command arguments inside WSL (mysql/mysqldump ...).
     * @param array<string,string> $env Environment variables for the command in WSL.
     * @return array{cmd:string|list<string>,env:array<string,string>} Command for proc_open; env is empty (everything is inside bash).
     */
    public function launch(array $argv, array $env = []): array
    {
        $bash = Command::bashString($argv, $env);

        if (Fs::isWindows()) {
            // ПОЧЕМУ скрипт-файл, а не `bash -c`:
            // 1) wsl.exe неверно разбирает отдельно закавыченные аргументы
            //    массив-формы proc_open (у него собственные правила склейки
            //    командной строки), а cmd.exe к тому же портит «сырую»
            //    строку `bash -c` с кавычками.
            // 2) Запуск `bash <файл>` читает команду из файла-аргумента:
            //    stdin остаётся свободным для SQL при импорте дампа, а код
            //    возврата последней команды скрипта естественно пробрасывается
            //    наружу (никаких обёрток, глотающих exit code).
            // 3) Пароль (MYSQL_PWD) лежит в файле, а не в argv wsl.exe —
            //    его не видно в списках процессов/командных строках.
            // WSL видит тот же файл через автомонтирование /mnt/<disk>/... .
            file_put_contents($this->scriptFile, $bash . "\n");
            $mapped = Fs::toWsl($this->scriptFile);
            $launcher = [$this->wslBin(), '-d', $this->distro(), '-u', $this->user(), '--', 'bash', $mapped];
            // Строковая форма команды: закавычиваем только токены с пробелами
            // (путь к wsl.exe или скрипту может лежать в «Program Files» и т.п.).
            $parts = [];
            foreach ($launcher as $token) {
                $parts[] = str_contains($token, ' ') ? '"' . $token . '"' : $token;
            }
            return ['cmd' => implode(' ', $parts), 'env' => []];
        }

        // Не Windows: промежуточного cmd.exe нет, массив-форма proc_open
        // передаёт аргументы без искажений — можно обойтись `bash -c`.
        $launcher = [$this->wslBin(), '-d', $this->distro(), '-u', $this->user(), '--', 'bash', '-c', $bash];
        return ['cmd' => $launcher, 'env' => []];
    }

    /**
     * Diagnostics: presence of wsl.exe on the machine, then probes of
     * `mysql --version` and `mysqldump --version` inside the distro (the same
     * path real commands take). info reports distro/user and the mysql version.
     *
     * @return array{ok:bool,info:string} ok — transport is ready; info — details or the failure reason.
     */
    public function doctor(): array
    {
        if (!Fs::hasBinary('wsl.exe') && !Fs::hasBinary('wsl')) {
            return ['ok' => false, 'info' => 'wsl.exe not found (WSL target requires Windows + WSL)'];
        }
        $probe = $this->versionProbe($this->mysqlBin());
        if (!$probe['ok']) {
            return ['ok' => false, 'info' => 'mysql inside WSL: ' . $probe['info']];
        }
        $dump = $this->versionProbe($this->mysqldumpBin());
        return [
            'ok' => $dump['ok'],
            'info' => sprintf('distro=%s user=%s | %s', $this->distro(), $this->user(), $probe['info']),
        ];
    }
}
