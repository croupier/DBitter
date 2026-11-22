<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Потоковая обёртка над proc_open для передачи больших объёмов данных
 * (дампы БД) без загрузки в память. Одновременно жив только ОДИН канал:
 * stdout в режиме 'read' (mysqldump -> PHP) или stdin в режиме 'write'
 * (PHP -> mysql); вторая сторона вывода направлена во временный файл.
 * Это исключает взаимную блокировку (deadlock) на заполненных буферах
 * каналов: родителю не нужно одновременно читать и писать. Используется
 * транспортами; результат завершения — Result, окружение — Command::mergeEnv.
 */
final class Process
{
    /** @var resource Handle процесса от proc_open. */
    private $proc;
    /** @var resource|null Единственный живой канал (stdout или stdin); null после finish(). */
    private $pipe = null;
    /** @var string Временный файл для stdout (заполняется только в режиме 'write'). */
    private string $outFile;
    /** @var string Временный файл для stderr — читается после завершения в любом режиме. */
    private string $errFile;
    /** @var float Момент старта (microtime) для подсчёта длительности в Result. */
    private float $start;
    /** @var string|list<string> Исходная команда — для отображения в Result. */
    private string|array $cmd;

    /**
     * Запускает процесс в одном из двух потоковых режимов.
     *
     * Побочные эффекты: создаёт два временных файла (stdout/stderr) в
     * Fs::tempDir(); они удаляются в finish() или при неудачном запуске.
     *
     * @param string|list<string> $cmd Команда proc_open (строка => шелл, массив => без шелла).
     * @param array<string,string> $env Добавления к окружению (например, MYSQL_PWD).
     * @param 'read'|'write' $mode 'read' — читаем stdout ребёнка; 'write' — пишем в его stdin.
     * @throws \RuntimeException Если proc_open не смог запустить процесс.
     */
    public function __construct(string|array $cmd, array $env, string $mode)
    {
        $this->cmd = $cmd;
        $this->start = microtime(true);
        $this->outFile = tempnam(Fs::tempDir(), 'dbt_pout_');
        $this->errFile = tempnam(Fs::tempDir(), 'dbt_perr_');

        if ($mode === 'read') {
            // Режим чтения: stdout — живой pipe (родитель стримит данные),
            // stdin закрывается сразу, stderr уходит в файл, чтобы его
            // буфер не заблокировал ребёнка при большом объёме ошибок.
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $this->errFile, 'w'],
            ];
        } else {
            // Режим записи: живой pipe — stdin (родитель льёт дамп),
            // stdout и stderr — в файлы: читать их параллельно не нужно.
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $this->outFile, 'w'],
                2 => ['file', $this->errFile, 'w'],
            ];
        }

        $proc = proc_open($cmd, $descriptors, $pipes, null, Command::mergeEnv($env));
        if (!is_resource($proc)) {
            // Запуск не удался — не оставляем мусорные временные файлы.
            @unlink($this->outFile);
            @unlink($this->errFile);
            throw new \RuntimeException('proc_open failed: ' . Command::display($cmd));
        }
        $this->proc = $proc;

        if ($mode === 'read') {
            // stdin ребёнку не нужен: закрываем, чтобы читающий до EOF
            // процесс не ждал ввода; живым остаётся только stdout-канал.
            fclose($pipes[0]);
            $this->pipe = $pipes[1];
        } else {
            $this->pipe = $pipes[0];
        }
    }

    /**
     * Отдаёт живой канал для потокового чтения/записи (fread/fwrite и т.п.).
     *
     * @return resource Канал stdout (режим 'read') или stdin (режим 'write').
     * @throws \RuntimeException Если канал уже закрыт через finish().
     */
    public function stream()
    {
        if ($this->pipe === null) {
            throw new \RuntimeException('Process pipe already closed');
        }
        return $this->pipe;
    }

    /**
     * Завершает процесс: закрывает живой канал (для ребёнка это EOF),
     * ждёт завершения через proc_close, собирает вывод из временных
     * файлов и удаляет их.
     *
     * Побочные эффекты: удаляет оба временных файла; повторный вызов
     * небезопасен (процесс уже закрыт).
     *
     * @return Result Код возврата, накопленные stdout/stderr, команда и время.
     */
    public function finish(): Result
    {
        if ($this->pipe !== null) {
            fclose($this->pipe);
            $this->pipe = null;
        }
        $code = proc_close($this->proc);
        $stdout = (string) file_get_contents($this->outFile);
        $stderr = (string) file_get_contents($this->errFile);
        @unlink($this->outFile);
        @unlink($this->errFile);

        return new Result($code, $stdout, $stderr, Command::display($this->cmd), microtime(true) - $this->start);
    }
}
