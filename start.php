<?php

/**
 * Точка входа DBitter — автономного CLI-инструмента миграции MySQL-баз.
 * Запуск: `php start.php <action> [--options]`; без действия открывается
 * интерактивное меню. Файл разбирает argv (dbt_parse_argv), настраивает
 * логирование, собирает Config (приоритет: CLI > config.local.php >
 * config.php > дефолты транспорта) и диспетчеризует действие в класс
 * из карты DBT_ACTIONS. Код возврата процесса — код действия (0 = успех).
 */

declare(strict_types=1);

// Бутстрап инструмента: CLI-guard, константа DbTools\ROOT, автозагрузчик
// классов DbTools\* и глобальные хелперы (lib/helpers.php).
require __DIR__ . '/lib/bootstrap.php';

use DbTools\Action\Action;
use DbTools\Action\CreateDb;
use DbTools\Action\CreateUser;
use DbTools\Action\Doctor;
use DbTools\Action\DropDb;
use DbTools\Action\ExportDump;
use DbTools\Action\ImportDump;
use DbTools\Action\ShowCommand;
use DbTools\Lib\Config;
use DbTools\Lib\Log;
use DbTools\Lib\Menu;

/**
 * Реестр действий: имя в CLI => класс-обработчик (реализация Action).
 * Используется и диспетчером, и справкой, и интерактивным меню —
 * добавление нового действия требует лишь одной строки здесь.
 */
const DBT_ACTIONS = [
    'doctor' => Doctor::class,
    'export' => ExportDump::class,
    'import' => ImportDump::class,
    'create-db' => CreateDb::class,
    'create-user' => CreateUser::class,
    'drop-db' => DropDb::class,
    'show-command' => ShowCommand::class,
];

/**
 * Разбирает argv в ассоциативный массив CLI-опций.
 *
 * Поддерживает: `--key=value`, `--key value`, голые bool-флаги (`--yes`),
 * повторяемые опции (`--extra`, `--ignore-table` — накапливаются в массив)
 * и позиционный аргумент-действие (первый аргумент без `--` → `_action`).
 *
 * @param array $argv сырой argv, включая имя скрипта нулевым элементом
 * @return array<string,mixed> опции + ключ '_action' (string|null)
 */
function dbt_parse_argv(array $argv): array
{
    // Нулевой элемент — путь к start.php, он не является опцией.
    array_shift($argv);
    $cli = [];
    $positional = [];
    // Повторяемые опции: каждое вхождение добавляет значение в список
    // (например, несколько --ignore-table=db.t или --extra=флаг).
    $repeat = ['extra', 'ignore-table'];
    // Флаги без значения: голый --dry-run означает true, а не «съедает»
    // следующий аргумент как своё значение.
    $bool = ['dry-run', 'yes', 'quiet', 'verbose', 'bootstrap', 'force', 'help'];

    for ($i = 0, $n = count($argv); $i < $n; $i++) {
        $arg = $argv[$i];
        // Всё, что не начинается с '--', — позиционный аргумент
        // (на практике используется только первый: имя действия).
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }
        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            // Форма --key=value: значение может само содержать '=' —
            // explode с лимитом 2 режет только по первому знаку.
            [$key, $val] = explode('=', $body, 2);
        } else {
            $key = $body;
            if (in_array($key, $bool, true)) {
                // Известный bool-флаг без значения → true.
                $val = true;
            } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                // Форма --key value: следующий аргумент не похож на флаг,
                // забираем его как значение (++$i пропускает его в цикле).
                $val = $argv[++$i];
            } else {
                // Неизвестный ключ без значения трактуем как флаг true.
                $val = true;
            }
        }
        if (in_array($key, $repeat, true)) {
            // Повторяемая опция: копим значения массивом, а не перезаписываем.
            $cli[$key][] = $val;
        } else {
            $cli[$key] = $val;
        }
    }
    // Первое позиционное слово — имя действия; null → интерактивное меню.
    $cli['_action'] = $positional[0] ?? null;
    return $cli;
}

/**
 * Печатает справку: список действий (заголовки берутся у самих классов,
 * чтобы справка не расходилась с реализацией) и обзор глобальных опций.
 */
function dbt_help(): void
{
    Menu::line('DBitter — universal MySQL migration tool');
    Menu::line();
    Menu::line('Usage: dbitter <action> [--options]   (or no action for interactive menu)');
    Menu::line();
    Menu::line('Actions:');
    foreach (DBT_ACTIONS as $name => $class) {
        /** @var Action $a */
        $a = new $class();
        Menu::line(sprintf('  %-14s %s', $name, $a->title()));
    }
    Menu::line();
    Menu::line('Global options:');
    Menu::line('  --target=local|wsl|docker|ssh|pdo   --conf=wp|laravel|env|const   --config=path');
    Menu::line('  --host= --port= --user= --pass= --name= --charset= --prefix= --socket=');
    Menu::line('  --dry-run (masked)   --yes (no prompts)   --quiet   --verbose   --log=path');
    Menu::line();
    Menu::line('Export: --kind=full|schema|data --compress=gzip|zstd|none --split=MiB');
    Menu::line('        --tables=a,b --ignore-table=db.t --where= --extra=flag (repeatable)');
    Menu::line('Import: --file=path --database=name --fk-checks=0|1 --force');
    Menu::line('Admin:  --collation= --user-host= --grant=ALL');
}

/**
 * Интерактивный режим (действие не передано в CLI): спрашивает действие
 * и недостающие параметры через Menu. Уже заданные в CLI ключи НЕ
 * переспрашиваются — приоритет CLI над интерактивным вводом сохраняется.
 *
 * @param array<string,mixed> $cli результат dbt_parse_argv
 * @return array<string,mixed> $cli, дополненный ответами пользователя
 */
function dbt_interactive(array $cli): array
{
    $titles = [];
    foreach (DBT_ACTIONS as $name => $class) {
        $titles[$name] = (new $class())->title();
    }
    $action = Menu::choice('Choose action', $titles, 'doctor');
    $cli['_action'] = $action;

    // Замыкание-хелпер: спросить параметр, только если его нет в CLI.
    // Проверка по array_key_exists (а не isset), чтобы явный --key=
    // с пустым значением тоже считался «уже заданным».
    $ask = static function (string $key, string $prompt, ?string $default) use (&$cli): void {
        if (!array_key_exists($key, $cli)) {
            $cli[$key] = Menu::ask($prompt, $default);
        }
    };

    if ($action === 'export') {
        // Параметры выгрузки: вид дампа, сжатие, размер частей.
        if (!isset($cli['kind'])) {
            $cli['kind'] = Menu::choice('Dump kind', ['full' => 'full (schema + data)', 'schema' => 'schema only', 'data' => 'data only'], 'full');
        }
        if (!isset($cli['compress'])) {
            $cli['compress'] = Menu::choice('Compression', ['gzip' => 'gzip (default)', 'zstd' => 'zstd (needs binary)', 'none' => 'none (plain .sql)'], 'gzip');
        }
        $ask('split', 'Split into parts of MiB (0 = single file)', '0');
    } elseif ($action === 'import') {
        // Параметры импорта: файл/манифест (пусто = последний дамп),
        // целевая БД и режим проверки внешних ключей.
        $ask('file', 'Dump file/manifest (blank = latest)', '');
        $ask('database', 'Target database (blank = from config)', '');
        if (!isset($cli['fk-checks'])) {
            $cli['fk-checks'] = Menu::confirm('Disable foreign key checks during import?', true) ? '1' : '0';
        }
    } elseif (in_array($action, ['create-db', 'create-user', 'drop-db'], true)) {
        // Админ-действия: имя БД (пусто = взять из конфигурации проекта).
        $ask('name', 'Database name (blank = from config)', '');
    }

    return $cli;
}

// --- Основная часть: разбор argv → логирование → диспетчер действий ---
$cli = dbt_parse_argv($argv);

// --help: только справка, никаких подключений к БД, успешный выход.
if (!empty($cli['help'])) {
    dbt_help();
    exit(0);
}

// Глобальная настройка вывода: --quiet/--verbose и опциональный лог-файл.
Log::configure(!empty($cli['quiet']), !empty($cli['verbose']), isset($cli['log']) ? (string) $cli['log'] : null);

try {
    // Действие не указано — запускаем интерактивное меню.
    if (empty($cli['_action'])) {
        $cli = dbt_interactive($cli);
    }
    $actionName = (string) $cli['_action'];
    // Неизвестное действие: ошибка + справка, код возврата 1.
    if (!isset(DBT_ACTIONS[$actionName])) {
        Log::error('Unknown action: ' . $actionName);
        dbt_help();
        exit(1);
    }

    // Config сам сливает config.php + config.local.php + CLI-переопределения
    // и извлекает параметры БД из конфига проекта (conf/*.php экстрактор).
    $config = new Config(__DIR__, $cli);
    /** @var Action $action */
    $action = new (DBT_ACTIONS[$actionName])();
    $code = $action->run($config, $cli);
    exit($code);
} catch (Throwable $e) {
    // Любое необработанное исключение → понятная ошибка и exit(1);
    // стек-трейс печатается только в режиме --verbose.
    Log::error($e->getMessage());
    if (!empty($cli['verbose'])) {
        Log::debug($e->getTraceAsString());
    }
    exit(1);
}
