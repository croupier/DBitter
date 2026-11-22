<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Интерактивное консольное меню: вывод строк, вопрос со значением по
 * умолчанию, подтверждение да/нет (с русскими "д/да") и выбор из списка
 * опций по номеру или ключу. Все методы работают через STDIN/STDOUT и
 * корректно ведут себя при неинтерактивном stdin (pipe/EOF): возвращают
 * значения по умолчанию вместо зависания или бесконечного цикла.
 * Используется интерактивным режимом start.php и действиями.
 */
final class Menu
{
    /**
     * Печатает строку в STDOUT с переводом строки (по умолчанию — пустую,
     * для визуального разделения блоков меню).
     *
     * @param string $text Текст строки.
     */
    public static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    /**
     * Задаёт вопрос и читает ответ из STDIN. Пустой ответ (или EOF)
     * означает значение по умолчанию; непустой default показывается
     * в подсказке как "[default]".
     *
     * @param string $prompt Текст вопроса (без двоеточия).
     * @param string|null $default Значение по умолчанию; null трактуется как ''.
     * @return string Ответ пользователя либо default.
     */
    public static function ask(string $prompt, ?string $default = null): string
    {
        $suffix = $default !== null && $default !== '' ? " [{$default}]" : '';
        fwrite(STDOUT, $prompt . $suffix . ': ');
        $line = fgets(STDIN);
        // EOF (перенаправленный/закрытый stdin): fgets вернёт false —
        // молча используем default, чтобы сценарий не завис.
        if ($line === false) {
            return $default ?? '';
        }
        $line = trim($line);
        return $line === '' ? ($default ?? '') : $line;
    }

    /**
     * Вопрос да/нет. Подсказка Y/n или y/N показывает, какой ответ
     * примется при пустом вводе. Понимает английские y/yes и русские
     * д/да (регистр не важен); всё остальное — "нет".
     *
     * @param string $prompt Текст вопроса.
     * @param bool $default Ответ при пустом вводе или EOF stdin.
     * @return bool true, если пользователь согласился.
     */
    public static function confirm(string $prompt, bool $default = true): bool
    {
        $hint = $default ? 'Y/n' : 'y/N';
        fwrite(STDOUT, "{$prompt} [{$hint}]: ");
        $line = fgets(STDIN);
        // EOF stdin — возвращаем default, а не крутим запрос повторно.
        if ($line === false) {
            return $default;
        }
        $line = strtolower(trim($line));
        if ($line === '') {
            return $default;
        }
        return in_array($line, ['y', 'yes', 'д', 'да'], true);
    }

    /**
     * Рисует нумерованный список опций и запрашивает выбор: принимается
     * номер пункта (1..N) или сам ключ опции. Пункт по умолчанию помечается
     * '*' и предлагается как default в ask(). Некорректный ввод приводит
     * к повторному запросу.
     *
     * @param string $title Заголовок меню.
     * @param array<string,string> $options Карта ключ => подпись пункта.
     * @param string|null $default Ключ пункта по умолчанию (или null).
     * @return string Выбранный ключ; при EOF — default, первый ключ или ''.
     */
    public static function choice(string $title, array $options, ?string $default = null): string
    {
        self::line();
        self::line($title);
        $keys = array_keys($options);
        foreach ($keys as $i => $key) {
            $num = $i + 1;
            $mark = ($default !== null && $key === $default) ? '*' : ' ';
            self::line(sprintf('  %s %2d) %s', $mark, $num, $options[$key]));
        }
        while (true) {
            // В подсказке показываем НОМЕР дефолтного пункта, а не его ключ:
            // пользователю достаточно нажать Enter.
            $answer = self::ask('Select', $default !== null ? (string) (array_search($default, $keys, true) + 1) : null);
            if ($answer === '') {
                // Защита от бесконечного цикла: при перенаправленном stdin
                // (pipe/EOF) ask() будет вечно возвращать пустую строку.
                // Если достигнут EOF — выходим на безопасный выбор
                // (default или первый пункт), а не спрашиваем снова.
                if (feof(STDIN)) {
                    return $default ?? ($keys[0] ?? '');
                }
                continue;
            }
            // Числовой ответ — индекс пункта (нумерация с 1).
            if (ctype_digit($answer)) {
                $idx = (int) $answer - 1;
                if (isset($keys[$idx])) {
                    return $keys[$idx];
                }
            }
            // Допускается ввод самого ключа опции, а не только номера.
            if (isset($options[$answer])) {
                return $answer;
            }
            self::line('  Invalid choice, try again.');
        }
    }
}
