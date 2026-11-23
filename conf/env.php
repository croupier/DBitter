<?php

declare(strict_types=1);

use DbTools\Lib\Env;

/**
 * Universal database credentials extractor from .env — without framework bootstrap.
 *
 * Understands three key styles: Laravel-style DB_*, "bare" MYSQL_* and
 * DATABASE_URL (Symfony/Doctrine etc., mysql/pgsql schemes). DATABASE_URL
 * values take precedence over the individual keys. Also used as the
 * fallback for the laravel.php extractor.
 */

/**
 * Returns the extractor closure: opts (root, path) -> normalized
 * database credentials array assembled from .env.
 *
 * @param array{root:string,path?:string} $opts Project root and optional path to .env (--config).
 * @return array<string,mixed> Normalized database credentials.
 * @throws RuntimeException If .env is empty/unparsed or the database name was not found anywhere.
 */
return static function (array $opts): array {
    $root = $opts['root'];
    $path = $opts['path'] ?: $root . '/.env';
    // Env::parse сам пропускает комментарии/пустые строки, снимает 'export '
    // и кавычки; отсутствующий файл даёт пустой массив.
    $env = Env::parse($path);
    if ($env === []) {
        throw new RuntimeException("No .env values parsed from: {$path}");
    }

    // Движок из DB_CONNECTION; алиас 'postgres' нормализуем в 'pgsql'.
    $engine = strtolower($env['DB_CONNECTION'] ?? 'mysql');
    if ($engine === 'pgsql' || $engine === 'postgres') {
        $engine = 'pgsql';
    }

    // Базовые значения: сначала DB_* (Laravel), затем MYSQL_* (простые проекты),
    // затем дефолты. Порт по умолчанию зависит от движжка: 5432 для pgsql, 3306 для mysql.
    $host = $env['DB_HOST'] ?? $env['MYSQL_HOST'] ?? '127.0.0.1';
    $port = (int) ($env['DB_PORT'] ?? $env['MYSQL_PORT'] ?? ($engine === 'pgsql' ? 5432 : 3306));
    $name = $env['DB_DATABASE'] ?? $env['MYSQL_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? $env['MYSQL_USER'] ?? 'root';
    $pass = $env['DB_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? '';

    // DATABASE_URL (например mysql://user:pass@host:3306/dbname или
    // postgres://...) перекрывает отдельные ключи: один URL — источник истины.
    if (!empty($env['DATABASE_URL'])) {
        $parts = parse_url($env['DATABASE_URL']);
        if ($parts !== false) {
            $host = $parts['host'] ?? $host;
            $port = (int) ($parts['port'] ?? $port);
            // Логин/пароль в URL процент-закодированы (спецсимволы вроде '@', ':')
            // — декодируем, иначе реквизиты не совпадут с реальными.
            $user = isset($parts['user']) ? urldecode($parts['user']) : $user;
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : $pass;
            // Имя БД — путь URL без ведущего слэша.
            $name = isset($parts['path']) ? ltrim($parts['path'], '/') : $name;
            $scheme = strtolower($parts['scheme'] ?? '');
            // Схема pgsql/postgres меняет движжок; mysql-схема ничего не меняет
            // (движок уже определён выше по DB_CONNECTION/умолчанию).
            if (str_contains($scheme, 'pgsql') || str_contains($scheme, 'postgres')) {
                $engine = 'pgsql';
            }
        }
    }

    // Имя БД обязательно: без него нельзя ни дампить, ни создавать базу.
    if ($name === '') {
        throw new RuntimeException('Database name not found in .env (DB_DATABASE / MYSQL_DATABASE / DATABASE_URL)');
    }

    return [
        'engine' => $engine,
        'host' => $host,
        'port' => $port,
        'socket' => $env['DB_SOCKET'] ?? null,
        'user' => $user,
        'pass' => $pass,
        'name' => $name,
        'charset' => $env['DB_CHARSET'] ?? 'utf8mb4',
        'prefix' => $env['DB_PREFIX'] ?? '',
        // Хост-маска для CREATE USER: .env описывает подключение клиента,
        // отдельного значения для маски нет — используем 'localhost'.
        'user_host' => 'localhost',
        'source' => $path,
    ];
};
