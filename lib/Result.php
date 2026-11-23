<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Immutable result of running an external command: exit code, captured
 * stdout/stderr, the command text for diagnostics and the elapsed duration.
 * Created in Command::capture and Process::finish; inspected by calling
 * code (transports, actions) via ok()/output().
 */
final class Result
{
    /**
     * @param int $code Process exit code (0 — success).
     * @param string $stdout Captured standard output.
     * @param string $stderr Captured error output.
     * @param string $command Displayable command line (secrets masked by the caller).
     * @param float $elapsed Runtime in seconds (fractional microtime).
     */
    public function __construct(
        public readonly int $code,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $command,
        public readonly float $elapsed = 0.0,
    ) {
    }

    /**
     * Whether the command completed successfully: the exit code equals 0 exactly.
     *
     * @return bool true on a zero exit code.
     */
    public function ok(): bool
    {
        return $this->code === 0;
    }

    /**
     * Combined output for error messages and diagnostics: stdout, then
     * stderr (when non-empty) separated by a newline, without edge whitespace.
     *
     * @return string Combined output without leading/trailing whitespace.
     */
    public function output(): string
    {
        return trim($this->stdout . ($this->stderr !== '' ? "\n" . $this->stderr : ''));
    }
}
