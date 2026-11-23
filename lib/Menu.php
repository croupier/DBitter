<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Interactive console menu: printing lines, a question with a default value,
 * a yes/no confirmation (accepting the Russian "д/да") and choosing from a
 * list of options by number or key. All methods work through STDIN/STDOUT
 * and behave correctly with non-interactive stdin (pipe/EOF): they return
 * default values instead of hanging or looping forever. Used by the
 * interactive mode of start.php and the actions.
 */
final class Menu
{
    /**
     * Prints a line to STDOUT with a newline (empty by default,
     * to visually separate menu blocks).
     *
     * @param string $text Line text.
     */
    public static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    /**
     * Asks a question and reads the answer from STDIN. An empty answer (or EOF)
     * means the default value; a non-empty default is shown in the prompt
     * as "[default]".
     *
     * @param string $prompt Question text (without the colon).
     * @param string|null $default Default value; null is treated as ''.
     * @return string The user's answer or default.
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
     * Yes/no question. The Y/n or y/N hint shows which answer is taken
     * on empty input. Understands the English y/yes and the Russian д/да
     * (case-insensitive); anything else means "no".
     *
     * @param string $prompt Question text.
     * @param bool $default Answer on empty input or stdin EOF.
     * @return bool true if the user agreed.
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
     * Renders a numbered list of options and asks for a choice: accepted are
     * the item number (1..N) or the option key itself. The default item is
     * marked with '*' and offered as the default in ask(). Invalid input
     * causes the prompt to repeat.
     *
     * @param string $title Menu title.
     * @param array<string,string> $options Map of key => item label.
     * @param string|null $default Key of the default item (or null).
     * @return string The chosen key; on EOF — default, the first key or ''.
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
