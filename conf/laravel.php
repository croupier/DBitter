<?php

declare(strict_types=1);

use DbTools\Lib\Log;

/**
 * Database credentials extractor for Laravel projects.
 *
 * Prefers the real framework bootstrap: that way the cached config
 * (config:cache), env substitutions and a non-standard default connection are
 * all honored. If bootstrap is disabled by the flag, impossible (no vendor/
 * or bootstrap/app.php) or fails — fall back transparently to plain .env parsing.
 */

/**
 * Returns the extractor closure: opts (root, path, bootstrap) -> normalized
 * database credentials array. On bootstrap failure it delegates to conf/env.php.
 *
 * @param array{root:string,path?:string,bootstrap?:bool} $opts Project root, path to .env, bootstrap flag (false — .env only).
 * @return array<string,mixed> Normalized database credentials.
 * @throws RuntimeException If neither bootstrap nor .env parsing yielded a database name (see conf/env.php).
 */
return static function (array $opts): array {
    $root = $opts['root'];
    // Явный запрет bootstrap (CLI-флаг): читаем только .env, код проекта не исполняем.
    $forceEnv = isset($opts['bootstrap']) && $opts['bootstrap'] === false;
    // Bootstrap возможен только при наличии обеих точек входа фреймворка;
    // без vendor/ (не установлены зависимости) сразу идём по fallback-пути.
    $canBootstrap = is_file($root . '/bootstrap/app.php') && is_file($root . '/vendor/autoload.php');

    if (!$forceEnv && $canBootstrap) {
        try {
            // Поднимаем приложение и консольное ядро: kernel->bootstrap()
            // загружает конфиг со всеми подстановками env() и учитывает
            // кэш конфигурации — значения будут такими же, как в рантайме.
            $app = require $root . '/bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            // Берём соединение по умолчанию (database.default), а не хардкодим mysql:
            // проект может использовать pgsql или иное именованное соединение.
            $default = config('database.default');
            $c = config("database.connections.{$default}");
            if (is_array($c) && !empty($c['database'])) {
                $driver = strtolower((string) ($c['driver'] ?? 'mysql'));
                return [
                    'engine' => str_contains($driver, 'pgsql') ? 'pgsql' : 'mysql',
                    'host' => (string) ($c['host'] ?? '127.0.0.1'),
                    'port' => (int) ($c['port'] ?? 3306),
                    'socket' => $c['unix_socket'] ?? null,
                    'user' => (string) ($c['username'] ?? 'root'),
                    'pass' => (string) ($c['password'] ?? ''),
                    'name' => (string) $c['database'],
                    'charset' => (string) ($c['charset'] ?? 'utf8mb4'),
                    'prefix' => (string) ($c['prefix'] ?? ''),
                    'user_host' => 'localhost',
                    'source' => "laravel bootstrap (connection: {$default})",
                ];
            }
        } catch (Throwable $e) {
            // Сломанное приложение/нехватка расширений не должны ронять экстрактор:
            // предупреждаем в лог и падаем в разбор .env ниже.
            Log::warn('Laravel bootstrap failed, falling back to .env: ' . $e->getMessage());
        }
    }

    // Fallback без vendor/bootstrap: переиспользуем generic .env-экстрактор
    // с теми же opts (root/path), чтобы поведение --config совпадало.
    $envExtractor = require __DIR__ . '/env.php';
    return $envExtractor($opts);
};
