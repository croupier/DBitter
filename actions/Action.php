<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Базовый класс всех действий DBitter (php start.php <action>).
 *
 * Содержит общую инфраструктуру: выбор транспорта (local/ssh/wsl), сборку
 * аргументов mysql/mysqldump без пароля в argv, выполнение SQL от админа
 * или прикладного пользователя, режим --dry-run и единые коды возврата.
 * Конкретные сценарии (export, import, create-db и т.д.) наследуются от него.
 */

use DbTools\Lib\Config;
use DbTools\Transport\Transport;

/**
 * Абстрактное действие CLI-инструмента.
 *
 * Определяет контракт (имя, заголовок, опции, запуск) и переиспользуемые
 * защищённые помощники для работы с MySQL через выбранный транспорт.
 */
abstract class Action
{
    /**
     * Машинное имя действия для вызова из CLI: php start.php <name>.
     *
     * @return string уникальный идентификатор действия (например, 'export')
     */
    abstract public function name(): string;

    /**
     * Человекочитаемый заголовок для интерактивного меню и справки.
     *
     * @return string описание действия одной строкой
     */
    abstract public function title(): string;

    /**
     * Список поддерживаемых CLI-опций действия для справки/меню.
     * По умолчанию опций нет; наследники переопределяют при необходимости.
     *
     * @return array<string,string> имя опции => описание (для help/меню)
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Точка входа действия: выполняет сценарий с учётом конфигурации и опций.
     *
     * @param Config $cfg  загруженная конфигурация проекта/окружения
     * @param array  $opts разобранные опции командной строки
     * @return int код завершения процесса: 0 — успех, ненулевой — ошибка
     */
    abstract public function run(Config $cfg, array $opts): int;

    /**
     * Создаёт транспорт по конфигурации (local, ssh или wsl).
     * Транспорт решает, где и как запускаются клиенты mysql/mysqldump.
     *
     * @param Config $cfg конфигурация с параметрами подключения и транспорта
     * @return Transport готовый транспорт для запуска клиентских команд
     */
    protected function transport(Config $cfg): Transport
    {
        return Transport::make($cfg);
    }

    /**
     * Базовый argv клиента mysql/mysqldump: бинарник, socket либо host:port,
     * пользователь и кодировка соединения. Пароль сюда НИКОГДА не попадает —
     * он передаётся через переменную окружения MYSQL_PWD, чтобы не светиться
     * в argv и списке процессов системы.
     *
     * @param Config      $cfg          конфигурация подключения к БД
     * @param bool        $dump         true — собираем mysqldump, false — mysql
     * @param string|null $userOverride подменить пользователя (например, на админа)
     * @return list<string> аргументы команды без пароля
     */
    protected function clientBase(Config $cfg, bool $dump = false, ?string $userOverride = null): array
    {
        $db = $cfg->db();
        $t = $this->transport($cfg);
        $bin = $dump ? $t->mysqldumpBin() : $t->mysqlBin();
        $argv = [$bin];
        // Socket имеет приоритет над host:port: прямое соединение через
        // unix-сокет быстрее и не зависит от сетевого стека (актуально для WSL).
        if (!empty($db['socket'])) {
            $argv[] = '--socket=' . $db['socket'];
        } else {
            $argv[] = '--host=' . $db['host'];
            $argv[] = '--port=' . $db['port'];
        }
        $argv[] = '--user=' . ($userOverride ?? $db['user']);
        $argv[] = '--default-character-set=' . $db['charset'];
        // mysql_extra добавляется ТОЛЬКО клиенту mysql: это флаги интерактивного
        // клиента (например, --init-command), которые mysqldump не понимает
        // и упал бы с ошибкой неизвестной опции. Для дампа есть mysqldump_extra.
        if (!$dump) {
            foreach ((array) $cfg->opt('mysql_extra', []) as $extra) {
                $argv[] = (string) $extra;
            }
        }
        return $argv;
    }

    /**
     * Выполняет SQL-стейтменты от админа (или прикладного пользователя).
     * Если сервер доступен из локального PHP — работает через PDO; иначе
     * подаёт SQL потоком в stdin клиента mysql через транспорт (ssh/wsl).
     * Перед выполнением нормализует sql_mode, чтобы экранирование строк
     * в Sql-хелперах гарантированно совпадало с ожиданиями сервера.
     *
     * Побочные эффекты: изменяет состояние БД (DDL/DML), может писать в лог.
     *
     * @param Config       $cfg        конфигурация подключения
     * @param list<string> $statements SQL-стейтменты для последовательного выполнения
     * @param bool         $useAdmin   true — реквизиты администратора, false — пользователя БД
     * @return array{ok:bool,msg:string} ok — успех, msg — текст ошибки сервера (пусто при успехе)
     */
    protected function execSql(Config $cfg, array $statements, bool $useAdmin = true): array
    {
        $db = $cfg->db();
        $t = $this->transport($cfg);
        $creds = $useAdmin ? $cfg->admin() : ['user' => $db['user'], 'pass' => $db['pass']];
        // Гарантируем наши предположения об экранировании даже если на сервере
        // включён NO_BACKSLASH_ESCAPES: Sql::NORMALIZE_SQL_MODE убирает этот режим
        // на время сессии, иначе обратные слеши в строках трактовались бы буквально
        // и закавыченные пароли/идентификаторы могли бы сломать SQL или стать инъекцией.
        $statements = array_merge([\DbTools\Lib\Sql::NORMALIZE_SQL_MODE], $statements);

        // Быстрый путь: сервер доступен напрямую из локального PHP — используем PDO,
        // это даёт точные сообщения об ошибках без парсинга вывода клиента.
        if ($t->pdoReachable() && extension_loaded('pdo_mysql')) {
            try {
                $pdo = \DbTools\Lib\Sql::connect(
                    $db['host'],
                    (int) $db['port'],
                    (string) $creds['user'],
                    (string) $creds['pass'],
                    null,
                    $db['charset'],
                    $db['socket'] ?? null
                );
                foreach ($statements as $sql) {
                    \DbTools\Lib\Sql::exec($pdo, $sql);
                }
                return ['ok' => true, 'msg' => ''];
            } catch (\Throwable $e) {
                return ['ok' => false, 'msg' => $e->getMessage()];
            }
        }

        // Запасной путь: PDO недоступен (например, MySQL внутри WSL/на удалёнке) —
        // склеиваем стейтменты через ';' и подаём их в stdin mysql через транспорт.
        // Пароль снова только в MYSQL_PWD, не в argv.
        $argv = $this->clientBase($cfg, false, (string) $creds['user']);
        $env = ['MYSQL_PWD' => (string) $creds['pass']];
        $launch = $t->launch($argv, $env);
        // У каждого стейтмента срезаем хвостовые ';' и пробелы, затем добавляем
        // единственный разделитель — чтобы не получить пустых команд ';;'.
        $sql = implode(";\n", array_map(static fn(string $s): string => rtrim($s, "; \t\n\r"), $statements)) . ";";
        $res = \DbTools\Lib\Command::capture($launch['cmd'], $launch['env'], $sql);
        return ['ok' => $res->ok(), 'msg' => trim($res->output())];
    }

    /**
     * Формирует окружение с паролем для клиента mysql/mysqldump.
     * MYSQL_PWD — единственный канал передачи пароля: он не виден в argv,
     * списке процессов и (после маскирования) в логах.
     *
     * @param Config      $cfg  конфигурация (пароль пользователя БД по умолчанию)
     * @param string|null $pass явный пароль (например, админский), если нужен не дефолтный
     * @return array<string,string> переменные окружения для запуска процесса
     */
    protected function pwdEnv(Config $cfg, ?string $pass = null): array
    {
        return ['MYSQL_PWD' => $pass ?? $cfg->db()['pass']];
    }

    /**
     * Режим --dry-run: печатает команду вместо её выполнения.
     * Показывает и внешний лаунчер (wsl.exe/ssh), и внутреннюю bash-команду;
     * все секреты из конфигурации маскируются в выводе.
     *
     * Побочные эффекты: только вывод в лог, ничего не запускает.
     *
     * @param Config $cfg  конфигурация (флаг dry-run и список секретов)
     * @param array  $argv аргументы клиентской команды
     * @param array  $env  окружение (включая MYSQL_PWD)
     * @return bool true — dry-run активен и команда показана (выполнять не нужно)
     */
    protected function dryRun(Config $cfg, array $argv, array $env): bool
    {
        if (empty($cfg->cli()['dry-run'])) {
            return false;
        }
        $t = $this->transport($cfg);
        $launch = $t->launch($argv, $env);
        // Маскируем пароли/секреты до вывода: dry-run не должен раскрывать реквизиты.
        $display = \DbTools\Lib\Command::display($launch['cmd']);
        $masked = \DbTools\Lib\Command::mask($display, $cfg->secrets());
        \DbTools\Lib\Log::info('[dry-run] ' . $masked);
        $inner = \DbTools\Lib\Command::mask(\DbTools\Lib\Command::bashString($argv, $env), $cfg->secrets());
        \DbTools\Lib\Log::info('[dry-run] inner: ' . $inner);
        return true;
    }

    /**
     * Завершает действие с ошибкой: пишет сообщение в лог уровня error.
     *
     * Побочные эффекты: вывод сообщения об ошибке в лог.
     *
     * @param string $msg текст ошибки для пользователя
     * @return int всегда 1 — код неуспешного завершения для CLI
     */
    protected function fail(string $msg): int
    {
        \DbTools\Lib\Log::error($msg);
        return 1;
    }

    /**
     * Завершает действие успехом: пишет итоговое сообщение в лог.
     *
     * Побочные эффекты: вывод сообщения в лог уровня info.
     *
     * @param string $msg итоговое сообщение для пользователя
     * @return int всегда 0 — код успешного завершения для CLI
     */
    protected function ok(string $msg): int
    {
        \DbTools\Lib\Log::info($msg);
        return 0;
    }
}
