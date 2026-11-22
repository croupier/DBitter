<?php

declare(strict_types=1);

/**
 * Экстрактор «const»: реквизиты БД берутся из PHP-файла, возвращающего массив.
 *
 * Файл может отдавать как полную нормализованную схему, так и обёртку
 * ['db' => [...]]. Дефолтный путь ищется цепочкой: dbitter.local.php и
 * dbitter.php в корне проекта (секреты — в local-файле, он не коммитится),
 * затем config.local.php в корне инструмента (legacy standalone-режим).
 * Путь переопределяется через --config. В отличие от wp-экстрактора этот
 * файл осознанно исполняется (require): это собственный конфиг пользователя,
 * а не чужой код проекта.
 */

/**
 * Возвращает замыкание-экстрактор: opts (root, path) -> нормализованный
 * массив реквизитов БД из require-конфига с подстановкой дефолтов.
 *
 * @param array{root:string,path?:string} $opts Корень проекта и необязательный путь к конфигу (--config).
 * @return array<string,mixed> Нормализованные реквизиты БД.
 * @throws RuntimeException Если файл отсутствует, вернул не массив или в нём нет имени БД.
 */
return static function (array $opts): array {
    $root = $opts['root'];
    // Цепочка дефолтов: проектные профили (local приоритетнее — там секреты),
    // затем legacy config.local.php в корне инструмента (dirname(__DIR__)).
    // Явный --config/path отменяет цепочку и берётся как есть.
    $candidates = $opts['path']
        ? [$opts['path']]
        : [
            $root . '/dbitter.local.php',
            $root . '/dbitter.php',
            dirname(__DIR__) . '/config.local.php',
        ];
    $path = null;
    $data = null;
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        // require возвращает значение `return` из файла — ожидаем массив.
        $loaded = require $candidate;
        if (!is_array($loaded)) {
            throw new RuntimeException("const config did not return an array: {$candidate}");
        }
        // Поддерживаем обе формы: обёртку ['db' => [...]] и плоский массив.
        // Файл без имени БД пропускаем: dbitter.local.php может содержать
        // только настройки транспорта/админа, а реквизиты — в dbitter.php.
        $candidateDb = $loaded['db'] ?? $loaded;
        if (!empty($candidateDb['name'])) {
            $path = $candidate;
            $data = $loaded;
            break;
        }
    }
    if ($path === null) {
        throw new RuntimeException(
            'const extractor needs a config file returning an array with a database name; none found. '
            . 'Create dbitter.local.php with a "db" key in the project root or pass --config=path.'
        );
    }
    $db = $data['db'] ?? $data;

    return [
        'engine' => $db['engine'] ?? 'mysql',
        'host' => $db['host'] ?? '127.0.0.1',
        'port' => (int) ($db['port'] ?? 3306),
        'socket' => $db['socket'] ?? null,
        'user' => $db['user'] ?? 'root',
        'pass' => $db['pass'] ?? '',
        'name' => $db['name'],
        'charset' => $db['charset'] ?? 'utf8mb4',
        'prefix' => $db['prefix'] ?? '',
        // Здесь user_host можно задать явно (в отличие от других экстракторов):
        // конфиг полностью под контролем пользователя.
        'user_host' => $db['user_host'] ?? 'localhost',
        'source' => $path,
    ];
};
