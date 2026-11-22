<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Fs;

/**
 * Транспорт «docker»: mysql/mysqldump выполняются внутри контейнера через
 * `docker exec` (по имени контейнера) или `docker compose exec` (по имени
 * сервиса). Команда доставляется оболочкой контейнера (bash либо sh),
 * env сворачивается в bash-строку — пароль не попадает в argv docker.
 */
final class Docker extends Transport
{
    /**
     * Имя транспорта для логов и диагностики.
     *
     * @return string Всегда 'docker'.
     */
    public function name(): string
    {
        return 'docker';
    }

    /**
     * PDO из локального PHP не используется: сервер живёт внутри контейнера,
     * порт наружу может быть не проброшен. SQL-действия идут через launch()
     * и бинарник mysql в контейнере.
     *
     * @return bool Всегда false.
     */
    public function pdoReachable(): bool
    {
        return false;
    }

    /**
     * Собирает обвязку запуска: два режима по конфигу транспорта.
     *
     * 1) Задан service — `docker compose [-f compose_file] exec -T <service> <shell> -c`:
     *    удобно, когда контейнер описан в compose-проекте; compose_file позволяет
     *    указать нестандартный файл. Флаг -T отключает псевдо-TTY (неинтерактивный
     *    запуск, stdin свободен для SQL при импорте).
     * 2) Задан container — `docker exec -i <container> <shell> -c`: прямой запуск
     *    по имени/ID контейнера; -i держит stdin открытым для импорта дампа.
     *
     * Оболочка настраивается ключом transport.shell: часть MySQL-образов
     * поставляется без bash, для них задают 'sh'.
     *
     * @return list<string> Аргументы обвязки; последним идёт '-c', после которого launch() добавит bash-строку.
     * @throws \RuntimeException Если не заданы ни container, ни service.
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
     * Оборачивает команду целевой стороны в запуск внутри контейнера.
     * Env (включая MYSQL_PWD) сворачивается в строку `<shell> -c`: переменные
     * окружения локального процесса не проникают через границу docker exec, поэтому
     * пароль передаётся присваиванием внутри bash-строки, а не в argv docker.
     *
     * @param list<string> $argv Аргументы команды внутри контейнера.
     * @param array<string,string> $env Переменные окружения для команды в контейнере.
     * @return array{cmd:string|list<string>,env:array<string,string>} Массив-команда для proc_open; env пуст.
     */
    public function launch(array $argv, array $env = []): array
    {
        return $this->viaBash($this->launcher(), $argv, $env);
    }

    /**
     * Диагностика: наличие бинарника docker на локальной машине, затем
     * probe `mysql --version` внутри контейнера — заодно проверяется, что
     * контейнер запущен и обвязка exec работает.
     *
     * @return array{ok:bool,info:string} ok — транспорт готов; info — версия mysql или текст ошибки.
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
