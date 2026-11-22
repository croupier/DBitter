<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Парсер .env-файлов (KEY=VALUE) без подключения их как PHP-кода —
 * безопасно читает недоверенные конфиги. Используется экстракторами
 * conf/env.php и conf/laravel.php для извлечения параметров БД
 * (DB_HOST, DB_DATABASE и т.п.), которые затем нормализует Config.
 */
final class Env
{
    /**
     * Разбирает .env-файл в ассоциативный массив. Поддерживает комментарии
     * (#), префикс export, одинарные/двойные кавычки и escape-последовательности
     * в двойных кавычках; для значений без кавычек отрезает хвостовой
     * комментарий вида " # ...". Это упрощённый парсер, не полный аналог
     * dotenv: многострочные значения не поддерживаются.
     *
     * @param string $path Путь к .env-файлу.
     * @return array<string,string> Пары ключ => значение; пустой массив, если файла нет.
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
