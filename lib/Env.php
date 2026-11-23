<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Parser for .env files (KEY=VALUE) that does not include them as PHP code —
 * it safely reads untrusted configs. Used by the conf/env.php and
 * conf/laravel.php config extractors to obtain the database credentials
 * (DB_HOST, DB_DATABASE and the like), which Config then normalizes.
 */
final class Env
{
    /**
     * Parses a .env file into an associative array. Supports comments
     * (#), the export prefix, single/double quotes and escape sequences
     * inside double quotes; for unquoted values it strips the trailing
     * comment of the form " # ...". This is a simplified parser, not a full
     * dotenv equivalent: multiline values are not supported.
     *
     * @param string $path Path to the .env file.
     * @return array<string,string> key => value pairs; empty array if the file does not exist.
     */
    public static function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Совместимость с shell-стилем: "export KEY=..." — префикс отбрасываем.
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            // Делим по ПЕРВОМУ '=': значение само может содержать '=' (пароли, URL).
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $quote = $val[0];
                // Снимаем парные кавычки; непарная закрывающая — оставляем как есть.
                if (str_ends_with($val, $quote)) {
                    $val = substr($val, 1, -1);
                }
                // Escape-последовательности интерпретируются только в двойных
                // кавычках; в одинарных содержимое берётся буквально (как в bash).
                if ($quote === '"') {
                    $val = str_replace(['\\n', '\\r', '\\t', '\\"'], ["\n", "\r", "\t", '"'], $val);
                }
            } else {
                // Без кавычек хвостовой комментарий отсекаем только по " #"
                // (с пробелом), чтобы не повредить значения с '#' внутри,
                // например пароль "pass#1".
                $hash = strpos($val, ' #');
                if ($hash !== false) {
                    $val = rtrim(substr($val, 0, $hash));
                }
            }
            $values[$key] = $val;
        }
        return $values;
    }
}
