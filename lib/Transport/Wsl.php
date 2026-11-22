<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Fs;

/**
 * Транспорт «wsl»: mysql/mysqldump выполняются внутри дистрибутива WSL
 * (по умолчанию Ubuntu) через wsl.exe. На Windows команда доставляется
 * bash-скриптом во временном файле, а не строкой `bash -c` — это обходит
 * проблемы экранирования wsl.exe/cmd.exe и сохраняет stdin и код возврата.
 */
final class Wsl extends Transport
{
    /** @var string Путь к временному bash-скрипту с текущей командой (уникален для процесса). */
    private string $scriptFile;

    /**
     * Готовит путь временного скрипта и регистрирует его удаление при
     * завершении PHP: в скрипт попадает MYSQL_PWD, поэтому файл не должен
     * оставаться на диске после работы (даже при фатальной ошибке).
     *
     * @param Config $cfg Конфигурация DBitter.
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
     * Находит исполняемый файл wsl: сначала wsl.exe, затем wsl в PATH;
     * если поиск не удался — возвращает 'wsl.exe' в надежде, что система
     * сама разрешит имя при запуске.
     *
     * @return string Путь к wsl.exe либо имя 'wsl.exe'.
     */
    private function wslBin(): string
    {
        return Fs::which('wsl.exe') ?? Fs::which('wsl') ?? 'wsl.exe';
    }

    /**
     * Имя дистрибутива WSL из конфига транспорта (transport.distro).
     *
     * @return string Дистрибутив; по умолчанию 'Ubuntu'.
     */
    private function distro(): string
    {
        return (string) ($this->tconfig['distro'] ?? 'Ubuntu');
    }

    /**
     * Пользователь WSL, под которым выполняется команда (transport.user).
     * По умолчанию root: так гарантированно доступны mysql/mysqldump,
     * сокет MySQL и файлы дампов на /mnt/<disk>/... без проблем с правами.
     *
     * @return string Имя пользователя; по умолчанию 'root'.
     */
    private function user(): string
    {
        return (string) ($this->tconfig['user'] ?? 'root');
    }

    /**
     * Имя транспорта для логов и диагностики.
     *
     * @return string Всегда 'wsl'.
     */
    public function name(): string
    {
        return 'wsl';
    }

    /**
     * PDO из локального (windows) PHP для этого транспорта не используется:
     * сервер живёт внутри WSL, SQL-действия идут тем же путём, что и дампы,
     * через launch() и бинарник mysql в дистрибутиве.
     *
     * @return bool Всегда false.
     */
    public function pdoReachable(): bool
    {
        return false;
    }

    /**
     * Оборачивает команду целевой стороны в запуск через wsl.exe + bash.
     *
     * На Windows — запись команды во временный скрипт-файл и запуск
     * `bash <путь>`; на не-Windows — обычный массив-запуск `bash -c`.
     * Env (включая MYSQL_PWD) сворачивается в bash-строку/скрипт, поэтому
     * пароль не попадает в argv самого wsl.exe.
     *
     * @param list<string> $argv Аргументы команды внутри WSL (mysql/mysqldump ...).
     * @param array<string,string> $env Переменные окружения для команды в WSL.
     * @return array{cmd:string|list<string>,env:array<string,string>} Команда для proc_open; env пуст (всё внутри bash).
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
     * Диагностика: наличие wsl.exe на машине, затем probe `mysql --version`
     * и `mysqldump --version` внутри дистрибутива (тем же путём, что и
     * реальные команды). В info выводятся distro/user и версия mysql.
     *
     * @return array{ok:bool,info:string} ok — транспорт готов; info — детали или причина отказа.
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
