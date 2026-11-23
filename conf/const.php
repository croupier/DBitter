<?php

declare(strict_types=1);

/**
 * "const" extractor: database credentials are taken from a PHP file returning an array.
 *
 * The file may return either the full normalized schema or a wrapper
 * ['db' => [...]]. The default path is resolved through a chain:
 * dbitter.local.php and dbitter.php in the project root (secrets go in the
 * local file, which is not committed), then config.local.php in the tool root
 * (legacy standalone mode). The path can be overridden via --config. Unlike
 * the wp extractor, this file is deliberately executed (require): it is the
 * user's own config file, not someone else's project code.
 */

/**
 * Returns the extractor closure: opts (root, path) -> normalized
 * database credentials array loaded from the require-based config with defaults applied.
 *
 * @param array{root:string,path?:string} $opts Project root and optional path to the config file (--config).
 * @return array<string,mixed> Normalized database credentials.
 * @throws RuntimeException If the file is missing, did not return an array, or contains no database name.
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
