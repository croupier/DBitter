<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Streaming wrapper over proc_open for transferring large amounts of data
 * (DB dumps) without loading them into memory. Only ONE channel is live at
 * a time: stdout in 'read' mode (mysqldump -> PHP) or stdin in 'write' mode
 * (PHP -> mysql); the other output side is redirected to a temp file.
 * This rules out mutual blocking (deadlock) on full channel buffers: the
 * parent never needs to read and write simultaneously. Used by the
 * transports; the completion result is a Result, the environment — Command::mergeEnv.
 */
final class Process
{
    /** @var resource Process handle from proc_open. */
    private $proc;
    /** @var resource|null The only live channel (stdout or stdin); null after finish(). */
    private $pipe = null;
    /** @var string Temp file for stdout (only filled in 'write' mode). */
    private string $outFile;
    /** @var string Temp file for stderr — read after completion in either mode. */
    private string $errFile;
    /** @var float Start moment (microtime) for computing duration in Result. */
    private float $start;
    /** @var string|list<string> Original command — for display in Result. */
    private string|array $cmd;

    /**
     * Starts a process in one of the two streaming modes.
     *
     * Side effects: creates two temp files (stdout/stderr) in Fs::tempDir();
     * they are deleted in finish() or on a failed start.
     *
     * @param string|list<string> $cmd proc_open command (string => shell, array => no shell).
     * @param array<string,string> $env Additions to the environment (e.g. MYSQL_PWD).
     * @param 'read'|'write' $mode 'read' — read the child's stdout; 'write' — write to its stdin.
     * @throws \RuntimeException If proc_open failed to start the process.
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
     * Returns the live channel for streaming reads/writes (fread/fwrite, etc.).
     *
     * @return resource The stdout channel ('read' mode) or stdin ('write' mode).
     * @throws \RuntimeException If the channel was already closed via finish().
     */
    public function stream()
    {
        if ($this->pipe === null) {
            throw new \RuntimeException('Process pipe already closed');
        }
        return $this->pipe;
    }

    /**
     * Finishes the process: closes the live channel (which is EOF for the
     * child), waits for completion via proc_close, collects the output from
     * the temp files and deletes them.
     *
     * Side effects: deletes both temp files; calling this again is unsafe
     * (the process is already closed).
     *
     * @return Result Exit code, accumulated stdout/stderr, command and elapsed time.
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
