<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Command;
use DbTools\Lib\Fs;

/**
 * Транспорт «local»: mysql/mysqldump запускаются напрямую на этой же машине
 * (например, бинарники из PATH или из набора Herd). Самый простой транспорт —
 * никакой обвязки, argv и env передаются процессу как есть.
 */
final class Local extends Transport
{
    /**
     * Имя транспорта для логов и диагностики.
     *
     * @return string Всегда 'local'.
     */
    public function name(): string
    {
        return 'local';
    }

    /**
     * Проброс команды без обёрток: массив-форма proc_open сама корректно
     * экранирует аргументы на Windows, а env (включая MYSQL_PWD) передаётся
     * процессу отдельно — пароль не оказывается ни в командной строке,
     * ни в argv mysql/mysqldump.
     *
     * @param list<string> $argv Аргументы команды, напр. ['mysqldump', 'db'].
     * @param array<string,string> $env Переменные окружения (MYSQL_PWD и др.).
     * @return array{cmd:string|list<string>,env:array<string,string>} cmd — исходный массив argv; env — исходный env.
     */
    public function launch(array $argv, array $env = []): array
    {
        return $this->passthrough($argv, $env);
    }

    /**
     * Диагностика: проверяет наличие обоих бинарников (по PATH или по прямому
     * пути из конфига), затем реально запускает `mysql --version` — это
     * подтверждает, что бинарник не только найден, но и исполняется.
     *
     * @return array{ok:bool,info:string} ok — успех проверки; info — версия mysql либо текст ошибки.
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
