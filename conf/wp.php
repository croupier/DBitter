<?php

declare(strict_types=1);

use DbTools\Lib\Fs;

/**
 * Экстрактор реквизитов БД из wp-config.php (WordPress).
 *
 * По умолчанию файл ТОЛЬКО парсится лексером PHP (token_get_all) и никогда
 * не выполняется через require: безопасность прежде всего — wp-config.php
 * тянет за собой wp-settings.php и весь WordPress, а исполнять чужой код
 * ради четырёх констант недопустимо. Исключение — явный флаг --bootstrap:
 * тогда загружается wp-load.php и читаются «живые» константы.
 */

/**
 * Возвращает замыкание-экстрактор: opts (root, path, bootstrap) -> нормализованный
 * массив реквизитов БД (engine/host/port/socket/user/pass/name/charset/prefix/...).
 *
 * @param array{root:string,path?:string,bootstrap?:bool} $opts Корень проекта, путь к конфигу, флаг bootstrap.
 * @return array<string,mixed> Нормализованные реквизиты БД.
 * @throws RuntimeException Если wp-config.php не найден или DB_NAME не распарсен.
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
 * Разбирает DB_HOST WordPress на host/port/socket.
 *
 * Поддерживаются три формы, которые понимает mysqli WordPress:
 * - 'host:1234'   — хост и числовой порт;
 * - 'host:/path'  — хост и unix-сокет (часть после ':' не число);
 * - 'host'        — просто хост, порт по умолчанию 3306.
 * Пустое значение заменяется на 'localhost'; для сокета пустой хост тоже
 * трактуется как 'localhost'.
 *
 * @param string $host Сырое значение DB_HOST.
 * @return array{host:string,port:int,socket:?string} Разобранные компоненты соединения.
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
