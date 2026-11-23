<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Simple static CLI logger: INFO/STEP/DEBUG/WARN/ERROR levels, output to
 * STDOUT/STDERR and (optionally) a log file. The --quiet and --verbose modes
 * are set once via configure() from start.php; afterwards all core classes
 * (Sql, transports, actions) write through it.
 * The file always receives every message, even ones suppressed on the console.
 */
final class Log
{
    /** @var bool --quiet: suppresses INFO/STEP on the console (the file is still written). */
    private static bool $quiet = false;
    /** @var bool --verbose: prints DEBUG to the console. */
    private static bool $verbose = false;
    /** @var resource|null Open log file (append mode) or null when no path was set. */
    private static $file = null;

    /**
     * Configures the output modes and, when a path is given, opens the log
     * file for appending (the directory is created if needed). Called once
     * at CLI startup.
     *
     * Side effects: creates the log directory (Fs::ensureDir) and opens the file.
     *
     * @param bool $quiet Suppress INFO/STEP on the console.
     * @param bool $verbose Show DEBUG on the console.
     * @param string|null $path Log file path; null — write only to the streams.
     */
    public static function configure(bool $quiet, bool $verbose, ?string $path): void
    {
        self::$quiet = $quiet;
        self::$verbose = $verbose;
        if ($path !== null) {
            Fs::ensureDir(dirname($path));
            self::$file = fopen($path, 'ab');
        }
    }

    /**
     * Regular informational message -> STDOUT (suppressed by --quiet).
     *
     * @param string $msg Message text.
     */
    public static function info(string $msg): void
    {
        self::write('INFO', $msg, self::$quiet ? null : STDOUT);
    }

    /**
     * Stage-start message ("==> ...") -> STDOUT; visually highlights the
     * scenario steps (export, import, etc.). Suppressed by --quiet.
     *
     * @param string $msg Stage name.
     */
    public static function step(string $msg): void
    {
        self::write('STEP', '==> ' . $msg, self::$quiet ? null : STDOUT);
    }

    /**
     * Debug message (SQL queries, commands, etc.): reaches the console only
     * with --verbose, but is always written to the log file — this is the
     * main data source for incident analysis.
     *
     * @param string $msg Debug text.
     */
    public static function debug(string $msg): void
    {
        if (self::$verbose) {
            self::write('DEBUG', $msg, STDOUT);
        } else {
            self::write('DEBUG', $msg, null);
        }
    }

    /**
     * Warning -> STDERR; not suppressed by --quiet, so problems stay visible
     * even in quiet mode.
     *
     * @param string $msg Warning text.
     */
    public static function warn(string $msg): void
    {
        self::write('WARN', $msg, STDERR);
    }

    /**
     * Error -> STDERR; not suppressed by --quiet.
     *
     * @param string $msg Error text.
     */
    public static function error(string $msg): void
    {
        self::write('ERROR', $msg, STDERR);
    }

    /**
     * Builds a "[HH:MM:SS] LEVEL msg" line and writes it to the given stream
     * (if not null) and to the log file (if open). Separating stream and file
     * output allows hiding messages on the console without losing them in the journal.
     *
     * Side effects: writes to stdout/stderr and/or the log file.
     *
     * @param string $level Level label (INFO/STEP/DEBUG/WARN/ERROR).
     * @param string $msg Message text.
     * @param resource|null $stream Console stream or null (file only).
     */
    private static function write(string $level, string $msg, $stream): void
    {
        $line = '[' . date('H:i:s') . "] {$level} {$msg}" . PHP_EOL;
        if ($stream !== null) {
            fwrite($stream, $line);
        }
        if (self::$file !== null) {
            fwrite(self::$file, $line);
        }
    }
}
