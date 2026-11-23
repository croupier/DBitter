<?php

declare(strict_types=1);

use DbTools\Lib\Fs;

/**
 * Database credentials extractor from wp-config.php (WordPress).
 *
 * By default the file is ONLY parsed with the PHP lexer (token_get_all) and
 * never executed via require: security first — wp-config.php pulls in
 * wp-settings.php and the whole WordPress, and executing someone else's code
 * for the sake of four constants is unacceptable. The sole exception is the
 * explicit --bootstrap flag: then wp-load.php is loaded and the "live"
 * constants are read.
 */

/**
 * Returns the extractor closure: opts (root, path, bootstrap) -> normalized
 * database credentials array (engine/host/port/socket/user/pass/name/charset/prefix/...).
 *
 * @param array{root:string,path?:string,bootstrap?:bool} $opts Project root, path to the config, bootstrap flag.
 * @return array<string,mixed> Normalized database credentials.
 * @throws RuntimeException If wp-config.php is not found or DB_NAME could not be parsed.
 */
return static function (array $opts): array {
    $root = $opts['root'];
    // Путь к конфигу можно задать явно (--config), иначе стандартный wp-config.php в корне.
    $path = $opts['path'] ?: $root . '/wp-config.php';
    if (!is_file($path)) {
        throw new RuntimeException("wp-config.php not found: {$path}");
    }

    if (!empty($opts['bootstrap'])) {
        // Режим --bootstrap: осознанное исключение из правила «не исполнять».
        // Загружаем wp-load.php, чтобы получить РЕАЛЬНЫЕ значения констант:
        // это единственный способ раскрыть getenv(...), условия и константы,
        // которые токенизатор вычислить не может. Код WordPress выполняется
        // только по явному запросу пользователя.
        require_once $root . '/wp-load.php';
        $host = DB_HOST;
        $parsed = dbt_wp_parse_host($host);
        return [
            'engine' => 'mysql',
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'socket' => $parsed['socket'],
            'user' => DB_USER,
            'pass' => DB_PASSWORD,
            'name' => DB_NAME,
            'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
            // Префикс берём из живого объекта $wpdb, а не из токенов.
            'prefix' => $GLOBALS['wpdb']->prefix ?? 'wp_',
            'user_host' => 'localhost',
            'source' => $path . ' (bootstrap)',
        ];
    }

    // Безопасный путь: лексический разбор содержимого файла. token_get_all
    // лишь разбивает текст на токены — никакой код не исполняется.
    $tokens = token_get_all((string) file_get_contents($path));
    $count = count($tokens);
    $wanted = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET'];
    $const = [];
    $prefix = 'wp_';

    // Ищет следующий «значимый» токен, пропуская пробелы/переносы строк
    // (T_WHITESPACE): между именем константы, запятой и значением в define()
    // может быть любое количество пробельных токенов.
    $nextMeaningful = static function (int $i) use ($tokens, $count): int {
        for ($j = $i; $j < $count; $j++) {
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                return $j;
            }
        }
        return $count;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        // Ищем шаблон define('DB_XXX', 'literal'): строковый литерал с именем
        // нужной константы, затем запятая, затем строковый литерал-значение.
        // ВАЖНО: извлекаются ТОЛЬКО литералы. Если значение задано через
        // getenv('DB_HOST'), константу или конкатенацию — токенизатор его
        // вычислить не может: ключ останется незаполненным и сработает
        // значение по умолчанию ниже (для таких конфигов нужен --bootstrap).
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($token[1], "'\""), $wanted, true)) {
            $name = trim($token[1], "'\"");
            $comma = $nextMeaningful($i + 1);
            if (($tokens[$comma] ?? null) === ',') {
                $valIdx = $nextMeaningful($comma + 1);
                if (is_array($tokens[$valIdx] ?? null) && $tokens[$valIdx][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $const[$name] = trim($tokens[$valIdx][1], "'\"");
                }
            }
        }
        // Префикс таблиц: присваивание $table_prefix = 'wp_'; (литерал).
        if ($token[0] === T_VARIABLE && $token[1] === '$table_prefix') {
            $eq = $nextMeaningful($i + 1);
            if (($tokens[$eq] ?? null) === '=') {
                $valIdx = $nextMeaningful($eq + 1);
                if (is_array($tokens[$valIdx] ?? null) && $tokens[$valIdx][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $prefix = trim($tokens[$valIdx][1], "'\"");
                }
            }
        }
    }

    // Без имени БД работать нельзя — это единственное обязательное поле.
    if (!isset($const['DB_NAME'])) {
        throw new RuntimeException('Could not parse DB_NAME from ' . $path);
    }

    $parsed = dbt_wp_parse_host($const['DB_HOST'] ?? 'localhost');

    return [
        'engine' => 'mysql',
        'host' => $parsed['host'],
        'port' => $parsed['port'],
        'socket' => $parsed['socket'],
        'user' => $const['DB_USER'] ?? 'root',
        'pass' => $const['DB_PASSWORD'] ?? '',
        'name' => $const['DB_NAME'],
        'charset' => $const['DB_CHARSET'] ?? 'utf8mb4',
        'prefix' => $prefix,
        // Хост-маска для CREATE USER ...@'localhost': WordPress подключается
        // локально, отдельного значения в wp-config.php нет.
        'user_host' => 'localhost',
        'source' => $path,
    ];
};

/**
 * Parses the WordPress DB_HOST value into host/port/socket.
 *
 * The three forms understood by WordPress' mysqli are supported:
 * - 'host:1234'   — host and numeric port;
 * - 'host:/path'  — host and unix socket (the part after ':' is not a number);
 * - 'host'        — plain host, default port 3306.
 * An empty value is replaced with 'localhost'; for a socket, an empty host
 * is also treated as 'localhost'.
 *
 * @param string $host Raw DB_HOST value.
 * @return array{host:string,port:int,socket:?string} Parsed connection components.
 */
function dbt_wp_parse_host(string $host): array
{
    $host = trim($host);
    // Сначала пробуем host:PORT — хвост строго из цифр.
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        return ['host' => $m[1], 'port' => (int) $m[2], 'socket' => null];
    }
    // Есть ':', но хвост не число — значит это путь к unix-сокету.
    if (str_contains($host, ':')) {
        [$h, $socket] = explode(':', $host, 2);
        return ['host' => $h !== '' ? $h : 'localhost', 'port' => 3306, 'socket' => $socket];
    }
    return ['host' => $host !== '' ? $host : 'localhost', 'port' => 3306, 'socket' => null];
}
