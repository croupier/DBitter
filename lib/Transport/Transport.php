<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Fs;

/**
 * Базовый абстрактный транспорт DBitter.
 *
 * Транспорт определяет, КАК запускаются бинарники mysql/mysqldump на целевой
 * стороне: local (напрямую), wsl (wsl.exe), docker (docker exec), ssh (удалённо),
 * pdo (без бинарников). Здесь собраны фабрика make(), общие помощники для
 * наследников (проброс команды, запуск через bash, проверка версии) и значения
 * по умолчанию для имён бинарников из конфигурации транспорта.
 */
abstract class Transport
{
    /** @var Config Общая конфигурация инструмента: профиль, реквизиты БД, CLI-опции. */
    protected Config $cfg;

    /** @var array<string,mixed> Настройки текущего транспорта — секция profile.transport[target]. */
    protected array $tconfig;

    /**
     * Запоминает конфигурацию и сразу извлекает секцию настроек транспорта,
     * чтобы наследники брали параметры (distro, container, host и т.п.)
     * из готового массива $tconfig, а не обращались к Config повторно.
     *
     * @param Config $cfg Конфигурация DBitter (профиль + CLI-опции).
     */
    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->tconfig = $cfg->transportConfig();
    }

    /**
     * Имя транспорта ('local', 'wsl', 'docker', 'ssh', 'pdo').
     * Используется в логах и диагностике для обозначения целевой стороны.
     *
     * @return string Короткое имя транспорта.
     */
    abstract public function name(): string;

    /**
     * Оборачивает argv (+env) целевой стороны в команду для proc_open.
     *
     * Каждый наследник решает, как доставить команду к mysql/mysqldump:
     * напрямую (Local), через wsl.exe/bash-скрипт (Wsl), через docker exec
     * или ssh (Docker/Ssh). Для bash-ориентированных целей переменные env
     * «сворачиваются» внутрь удалённой команды: proc_open-env не пересекает
     * границу ssh/docker, поэтому MYSQL_PWD и прочие значения передаются
     * как присваивания KEY='value' перед самой командой.
     *
     * @param list<string> $argv Аргументы команды на целевой стороне (например ['mysqldump', 'db']).
     * @param array<string,string> $env Переменные окружения для целевого процесса (в т.ч. MYSQL_PWD).
     * @return array{cmd:string|list<string>,env:array<string,string>} Команда и env для Command::run/capture.
     */
    abstract public function launch(array $argv, array $env = []): array;

    /**
     * Самодиагностика транспорта: проверяет доступность обвязки (wsl.exe,
     * docker, ssh, расширение pdo_mysql) и работоспособность mysql на цели.
     *
     * @return array{ok:bool,info:string} ok — всё ли готово к работе; info — человекочитаемая деталь.
     */
    abstract public function doctor(): array;

    /**
     * Имя/путь бинарника mysql на целевой стороне.
     * Позволяет переопределить имя в конфиге транспорта (например, mariadb
     * или абсолютный путь); по умолчанию — просто 'mysql' из PATH цели.
     *
     * @return string Значение transport.mysql либо 'mysql'.
     */
    public function mysqlBin(): string
    {
        return (string) ($this->tconfig['mysql'] ?? 'mysql');
    }

    /**
     * Имя/путь бинарника mysqldump на целевой стороне.
     * Аналогично mysqlBin(): переопределяется ключом transport.mysqldump.
     *
     * @return string Значение transport.mysqldump либо 'mysqldump'.
     */
    public function mysqldumpBin(): string
    {
        return (string) ($this->tconfig['mysqldump'] ?? 'mysqldump');
    }

    /**
     * Можно ли SQL-действиям (create-db/create-user/drop-db) использовать
     * PDO-соединение прямо из локального PHP, минуя бинарник mysql.
     *
     * По умолчанию true (Local/Pdo). Транспорты, где сервер живёт внутри
     * изолированного окружения (WSL, контейнер, удалённый хост), возвращают
     * false: SQL выполняется тем же путём, что и дампы — через launch().
     *
     * @return bool true, если локальный PDO до сервера гарантированно дотянется.
     */
    public function pdoReachable(): bool
    {
        return true;
    }

    /**
     * Фабрика: создаёт транспорт по значению target из CLI/профиля.
     * Единая точка выбора реализации — вызывающий код не знает о классах.
     *
     * @param Config $cfg Конфигурация; target берётся из $cfg->target().
     * @return self Экземпляр Local, Wsl, Docker, Ssh или Pdo.
     * @throws \RuntimeException Если target не распознан (опечатка в конфиге/CLI).
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
     * Проброс команды без изменений: argv остаётся массивом, env — отдельным
     * массивом. Массив-форма proc_open на Windows сама корректно экранирует
     * аргументы, а env передаётся процессу напрямую — пароль (MYSQL_PWD)
     * не попадает ни в командную строку, ни в argv mysql.
     *
     * @param list<string> $argv Аргументы команды.
     * @param array<string,string> $env Переменные окружения.
     * @return array{cmd:string|list<string>,env:array<string,string>} Пара для Command::run/capture.
     */
    protected function passthrough(array $argv, array $env): array
    {
        return ['cmd' => $argv, 'env' => $env];
    }

    /**
     * Запуск через bash для ssh/docker (массив-форма proc_open).
     *
     * Env сворачивается в строку удалённой команды (`KEY='val' ... cmd args`,
     * см. Command::bashString): иначе переменные остались бы на локальной
     * стороне и не дошли бы до mysql внутри контейнера/на хосте. Пароль тем
     * самым передаётся как MYSQL_PWD внутри bash-строки, а не как аргумент mysql.
     * ssh.exe/docker.exe корректно разбирают закавыченные элементы массива,
     * в отличие от wsl.exe — у Wsl поэтому отдельный запуск через скрипт-файл.
     *
     * @param list<string> $launcher Обвязка запуска, напр. ['ssh', ..., 'user@host'] или ['docker', 'exec', ...].
     * @param list<string> $argv Аргументы команды на целевой стороне.
     * @param array<string,string> $env Переменные окружения, сворачиваемые в bash-строку.
     * @return array{cmd:string|list<string>,env:array<string,string>} cmd — массив с bash-строкой последним элементом; env пуст.
     */
    protected function viaBash(array $launcher, array $argv, array $env): array
    {
        $launcher[] = Command::bashString($argv, $env);
        return ['cmd' => $launcher, 'env' => []];
    }

    /**
     * Проверка бинарника на целевой стороне запуском `<bin> --version`
     * через launch() — то есть тем же путём, каким пойдут реальные команды.
     * Заодно проверяет всю обвязку транспорта (wsl/docker/ssh), а не только
     * наличие файла.
     *
     * @param string $bin Имя бинарника (mysql/mysqldump).
     * @return array{ok:bool,info:string} ok — код возврата 0; info — stdout при успехе, иначе весь вывод (включая stderr).
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
     * Проверка наличия бинарника на ЛОКАЛЬНОЙ машине: поиск по PATH
     * (Fs::hasBinary) либо по прямому пути к файлу — конфиг может задавать
     * абсолютный путь вместо имени.
     *
     * @param string $bin Имя из PATH или путь к файлу.
     * @return bool true, если бинарник найден.
     */
    protected function localBinaryCheck(string $bin): bool
    {
        return Fs::hasBinary($bin) || is_file($bin);
    }
}
