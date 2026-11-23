<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * External command execution utilities: safe argument escaping for
 * bash (wsl/ssh/docker), masking of secrets in displayed command
 * lines, correct env merging for proc_open and synchronous execution of short
 * commands with output capture (Command::capture). For streaming large
 * dumps Process is used; the result of both is a Result.
 */
final class Command
{
    /**
     * Escapes a value for bash using single quotes: inside '...'
     * bash interprets nothing except the single quote itself,
     * so each ' is replaced with the sequence '\'' (close the
     * quote, escaped quote, reopen it). Protects passwords
     * and paths with spaces/special characters when passed via `bash -c`.
     *
     * @param string $value Arbitrary value (password, path, argument).
     * @return string Value that is safe to embed in a bash command line.
     */
    public static function bashQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /**
     * Serializes argv (plus environment variable exports) into a single
     * bash-safe command line. Used inside `bash -c`
     * for the wsl/ssh/docker transports: each argument is quoted
     * separately, so the line survives several shell levels.
     *
     * @param list<string> $argv Command arguments (program and parameters).
     * @param array<string,string> $env Variables of the form KEY=VALUE that
     *        are placed before the command (so that MYSQL_PWD does not end up in argv).
     * @return string A ready-to-use line for `bash -c '...'`.
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
     * Masks secrets in a command line before it is written to the log/console,
     * so that passwords do not leak into stdout or the log file. The password
     * is hidden in these forms: quoted literal 'password', MYSQL_PWD='...',
     * --password=..., -p...; for secrets of 4 characters or more, a bare
     * substring occurrence is additionally replaced.
     *
     * @param string $command Command line to be displayed.
     * @param list<string> $secrets List of secrets (database passwords and admin password).
     * @return string Line with secrets replaced by ***.
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
     * Prepares the env for proc_open. Important: if you pass an environment
     * array to proc_open, it COMPLETELY REPLACES the child's environment
     * instead of augmenting it. On Windows this would deprive the child process of PATH/SystemRoot
     * and similar, which makes external binaries (and PHP itself) stop working. That is why
     * overrides are merged on top of the inherited getenv(): the caller
     * can add MYSQL_PWD without wiping out the rest of the environment.
     *
     * @param array<string,string> $env Overrides/additions to the environment.
     * @return array<string,string>|null null when the list is empty — proc_open
     *         then inherits the parent's environment as is.
     */
    public static function mergeEnv(array $env): ?array
    {
        if ($env === []) {
            return null;
        }
        return array_merge((array) getenv(), $env);
    }

    /**
     * Renders a command as a string for display (logs, Result::$command).
     * An argument array is simply joined with spaces — this is diagnostic
     * text, not an executable line; secrets are masked separately (mask).
     *
     * @param string|list<string> $cmd Command in any of the proc_open formats.
     * @return string Human-readable representation of the command.
     */
    public static function display(string|array $cmd): string
    {
        return is_string($cmd) ? $cmd : implode(' ', $cmd);
    }

    /**
     * Runs a short command synchronously until it completes, capturing the output
     * into temp files (rather than a pipe): this rules out a deadlock on channel
     * buffer exhaustion when the child writes a lot while the parent has not
     * started reading yet. stdin, when needed, is filled from a string.
     * For large streams (dumps) Process is used, not this method.
     *
     * Side effects: creates and deletes two temporary files in Fs::tempDir().
     *
     * @param string|list<string> $cmd proc_open command (string => via shell, array => without shell).
     * @param array<string,string> $env Additions to the environment (see mergeEnv).
     * @param string|null $stdin Data for the child's stdin; null — empty stdin.
     * @return Result Exit code, stdout/stderr, command text and execution time.
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
