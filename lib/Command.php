<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Утилиты запуска внешних команд: безопасное экранирование аргументов для
 * bash (wsl/ssh/docker), маскировка секретов в отображаемых командных
 * строках, корректное слияние env для proc_open и синхронный запуск коротких
 * команд с захватом вывода (Command::capture). Для потоковой передачи
 * больших дампов используется Process, результат обеих — Result.
 */
final class Command
{
    /**
     * Экранирует значение для bash одинарными кавычками: внутри '...'
     * bash не интерпретирует ничего, кроме самой одинарной кавычки,
     * поэтому каждая ' заменяется на последовательность '\'' (закрыть
     * кавычку, экранированная кавычка, открыть заново). Защищает пароли
     * и пути с пробелами/спецсимволами при передаче через `bash -c`.
     *
     * @param string $value Произвольное значение (пароль, путь, аргумент).
     * @return string Значение, безопасно вставляемое в bash-строку.
     */
    public static function bashQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /**
     * Сериализует argv (плюс экспорты переменных окружения) в одну
     * bash-безопасную командную строку. Используется внутри `bash -c`
     * для транспортов wsl/ssh/docker: каждый аргумент quoting-ается
     * отдельно, поэтому строка переживает несколько уровней шеллов.
     *
     * @param list<string> $argv Аргументы команды (программа и параметры).
     * @param array<string,string> $env Переменные вида KEY=VALUE, которые
     *        ставятся перед командой (так MYSQL_PWD не попадает в argv).
     * @return string Готовая строка для `bash -c '...'`.
     */
    public static function bashString(array $argv, array $env = []): string
    {
        $parts = [];
        // Префикс KEY='value' — bash трактует его как окружение только для
        // этой команды; значение экранируется так же, как аргументы.
        foreach ($env as $key => $val) {
            $parts[] = $key . '=' . self::bashQuote($val);
        }
        foreach ($argv as $arg) {
            $parts[] = self::bashQuote($arg);
        }
        return implode(' ', $parts);
    }

    /**
     * Маскирует секреты в командной строке перед выводом в лог/консоль,
     * чтобы пароли не утекали в stdout и лог-файл. Пароль скрывается в
     * формах: quoted-литерал 'пароль', MYSQL_PWD='...', --password=...,
     * -p...; для секретов длиной >= 4 символов дополнительно заменяется
     * голое вхождение подстроки.
     *
     * @param string $command Командная строка для отображения.
     * @param list<string> $secrets Список секретов (пароли БД и админа).
     * @return string Строка с заменёнными на *** секретами.
     */
    public static function mask(string $command, array $secrets): string
    {
        // MYSQL_PWD маскируем по шаблону независимо от списка секретов:
        // значение могло прийти из окружения, а не из Config::secrets().
        $command = preg_replace("/MYSQL_PWD='[^']*'/", "MYSQL_PWD='***'", $command) ?? $command;
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            // Сначала точечные формы с разделителями — они не задевают
            // случайные совпадения внутри других слов.
            $command = str_replace(["'" . $secret . "'", '--password=' . $secret, '-p' . $secret], "'***'", $command);
            // Голое вхождение заменяем только для достаточно длинных секретов:
            // маскировка 1-символьного пароля как подстроки изуродовала бы
            // весь текст команды, поэтому короткие секреты полагаются на
            // формы с разделителями выше.
            if (strlen($secret) >= 4) {
                $command = str_replace($secret, '***', $command);
            }
        }
        return $command;
    }

    /**
     * Готовит env для proc_open. Важно: если передать в proc_open массив
     * окружения, он ПОЛНОСТЬЮ ЗАМЕНЯЕТ окружение ребёнка, а не дополняет
     * его. На Windows это лишит дочерний процесс PATH/SystemRoot и т.п.,
     * из-за чего внешние бинарники (и сам PHP) перестают работать. Поэтому
     * переопределения сливаются поверх унаследованного getenv(): вызывающий
     * может добавить MYSQL_PWD, не затирая остальное окружение.
     *
     * @param array<string,string> $env Переопределения/добавления к окружению.
     * @return array<string,string>|null null при пустом списке — proc_open
     *         тогда наследует окружение родителя как есть.
     */
    public static function mergeEnv(array $env): ?array
    {
        if ($env === []) {
            return null;
        }
        return array_merge((array) getenv(), $env);
    }

    /**
     * Приводит команду к строке для отображения (логи, Result::$command).
     * Массив аргументов просто склеивается пробелами — это диагностический
     * текст, а не исполняемая строка; секреты маскируются отдельно (mask).
     *
     * @param string|list<string> $cmd Команда в любом из форматов proc_open.
     * @return string Человекочитаемое представление команды.
     */
    public static function display(string|array $cmd): string
    {
        return is_string($cmd) ? $cmd : implode(' ', $cmd);
    }

    /**
     * Синхронно выполняет короткую команду до завершения, захватывая вывод
     * во временные файлы (а не в pipe): это исключает deadlock на заполнении
     * буфера канала, когда ребёнок пишет много, а родитель ещё не читает.
     * stdin при необходимости заполняется из строки. Для больших потоков
     * (дампы) используется Process, а не этот метод.
     *
     * Побочные эффекты: создаёт и удаляет два временных файла в Fs::tempDir().
     *
     * @param string|list<string> $cmd Команда proc_open (строка => через шелл, массив => без шелла).
     * @param array<string,string> $env Добавления к окружению (см. mergeEnv).
     * @param string|null $stdin Данные для stdin ребёнка; null — пустой stdin.
     * @return Result Код возврата, stdout/stderr, текст команды и время выполнения.
     */
    public static function capture(string|array $cmd, array $env = [], ?string $stdin = null): Result
    {
        $start = microtime(true);
        // stdout/stderr пишутся ребёнком напрямую в файлы — родитель не
        // обязан читать их во время работы, буфер канала не переполнится.
        $outFile = tempnam(Fs::tempDir(), 'dbt_out_');
        $errFile = tempnam(Fs::tempDir(), 'dbt_err_');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, self::mergeEnv($env));
        if (!is_resource($proc)) {
            // Не удалось запустить процесс: убираем временные файлы и
            // возвращаем Result с кодом 127 (аналог "command not found").
            @unlink($outFile);
            @unlink($errFile);
            return new Result(127, '', 'proc_open failed', self::display($cmd));
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        // stdin закрываем всегда — иначе ребёнок, читающий до EOF, зависнет.
        fclose($pipes[0]);
        $code = proc_close($proc);
        $stdout = (string) file_get_contents($outFile);
        $stderr = (string) file_get_contents($errFile);
        @unlink($outFile);
        @unlink($errFile);

        return new Result($code, $stdout, $stderr, self::display($cmd), microtime(true) - $start);
    }
}
