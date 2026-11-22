<?php

declare(strict_types=1);

namespace DbTools;

/**
 * Точка инициализации ядра DBitter: подключается первым из start.php.
 * Проверяет, что запуск идёт из CLI, определяет константу ROOT (корень
 * инструмента), регистрирует автозагрузчик классов пространств DbTools\Lib,
 * DbTools\Transport, DbTools\Conf и DbTools\Action, а также подключает
 * глобальные функции-помощники из lib/helpers.php. Composer не используется.
 */

// Инструмент рассчитан только на консольный запуск: при обращении через
// веб-сервер (Herd/Apache и т.п.) немедленно завершаемся, чтобы случайно
// не отдать наружу операции с БД и дампами.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// ROOT — каталог DBitter (на уровень выше lib/). От него строятся все
// пути: conf/, actions/, dump/, config.php и т.д.
define('DbTools\ROOT', dirname(__DIR__));

// Простой PSR-4-подобный автозагрузчик без Composer: префикс DbTools\
// отбрасывается, а оставшаяся часть имени класса сопоставляется с каталогом
// по карте ниже. Классы вне нашего префикса игнорируются (return без require).
spl_autoload_register(static function (string $class): void {
    $prefix = 'DbTools\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    // Карта «подпространство => каталог»: ядро, транспорты (local/wsl/docker/
    // ssh/pdo), экстракторы конфигов (wp/laravel/env/const) и действия CLI.
    $map = [
        'Lib\\' => ROOT . '/lib/',
        'Transport\\' => ROOT . '/lib/Transport/',
        'Conf\\' => ROOT . '/conf/',
        'Action\\' => ROOT . '/actions/',
    ];
    foreach ($map as $ns => $dir) {
        if (str_starts_with($rel, $ns)) {
            $file = $dir . str_replace('\\', '/', substr($rel, strlen($ns))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            // Выходим даже если файла нет: пространство найдено, перебирать
            // остальные каталоги бессмысленно — PHP сам сообщит об ошибке класса.
            return;
        }
    }
});

// Функции dbt_root()/dbt_project()/dbt_now() нужны многим классам, поэтому
// подключаются сразу, а не через автозагрузчик (он работает только для классов).
require ROOT . '/lib/helpers.php';
