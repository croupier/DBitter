<?php

declare(strict_types=1);

/**
 * Экстрактор «const»: реквизиты БД берутся из PHP-файла, возвращающего массив.
 *
 * Файл может отдавать как полную нормализованную схему, так и обёртку
 * ['db' => [...]]. По умолчанию читается config.local.php в корне DBitter —
 * чтобы секреты не попадали в коммиты; путь переопределяется через --config.
 * В отличие от wp-экстрактора этот файл осознанно исполняется (require):
 * это собственный конфиг пользователя, а не чужой код проекта.
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
    // Дефолт — config.local.php рядом с lib/ (dirname(__DIR__) = корень DBitter),
    // а не в корне проекта: локальный файл с секретами не коммитится.
    $path = $opts['path'] ?: dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) {
        throw new RuntimeException(
            "const extractor needs a config file returning an array; none at {$path}. "
            . 'Create config.local.php with a "db" key or pass --config=path.'
        );
    }
    // require возвращает значение `return` из файла — ожидаем массив.
    $data = require $path;
    if (!is_array($data)) {
        throw new RuntimeException("const config did not return an array: {$path}");
    }
    // Поддерживаем обе формы: обёртку ['db' => [...]] и плоский массив реквизитов.
    $db = $data['db'] ?? $data;

    // Единственное обязательное поле — имя БД; остальное имеет дефолты.
    if (empty($db['name'])) {
        throw new RuntimeException('const config missing "name" (database name)');
    }

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
