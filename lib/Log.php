<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Простой статический логгер CLI: уровни INFO/STEP/DEBUG/WARN/ERROR,
 * вывод в STDOUT/STDERR и (опционально) в лог-файл. Режимы --quiet и
 * --verbose задаются один раз через configure() из start.php; далее
 * все классы ядра (Sql, транспорты, действия) пишут через него.
 * Файл всегда получает все сообщения, даже подавленные на консоли.
 */
final class Log
{
    /** @var bool --quiet: подавляет INFO/STEP на консоли (файл всё равно пишется). */
    private static bool $quiet = false;
    /** @var bool --verbose: выводит DEBUG на консоль. */
    private static bool $verbose = false;
    /** @var resource|null Открытый лог-файл (режим append) или null, если путь не задан. */
    private static $file = null;

    /**
     * Настраивает режимы вывода и, при заданном пути, открывает лог-файл
     * на дозапись (каталог создаётся при необходимости). Вызывается один
     * раз при старте CLI.
     *
     * Побочные эффекты: создаёт каталог лога (Fs::ensureDir) и открывает файл.
     *
     * @param bool $quiet Подавлять INFO/STEP на консоли.
     * @param bool $verbose Показывать DEBUG на консоли.
     * @param string|null $path Путь лог-файла; null — писать только в потоки.
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
     * Обычное информационное сообщение -> STDOUT (подавляется --quiet).
     *
     * @param string $msg Текст сообщения.
     */
    public static function info(string $msg): void
    {
        self::write('INFO', $msg, self::$quiet ? null : STDOUT);
    }

    /**
     * Сообщение о начале этапа ("==> ...") -> STDOUT; визуально выделяет
     * шаги сценария (экспорт, импорт и т.п.). Подавляется --quiet.
     *
     * @param string $msg Название этапа.
     */
    public static function step(string $msg): void
    {
        self::write('STEP', '==> ' . $msg, self::$quiet ? null : STDOUT);
    }

    /**
     * Отладочное сообщение (SQL-запросы, команды и т.п.): на консоль
     * попадает только при --verbose, но в лог-файл пишется всегда —
     * это основной источник данных для разбора инцидентов.
     *
     * @param string $msg Текст отладки.
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
     * Предупреждение -> STDERR; не подавляется --quiet, чтобы проблемы
     * были видны даже в тихом режиме.
     *
     * @param string $msg Текст предупреждения.
     */
    public static function warn(string $msg): void
    {
        self::write('WARN', $msg, STDERR);
    }

    /**
     * Ошибка -> STDERR; не подавляется --quiet.
     *
     * @param string $msg Текст ошибки.
     */
    public static function error(string $msg): void
    {
        self::write('ERROR', $msg, STDERR);
    }

    /**
     * Формирует строку "[ЧЧ:ММ:СС] LEVEL msg" и пишет её в указанный поток
     * (если не null) и в лог-файл (если открыт). Разделение потоков и файла
     * позволяет скрывать сообщения на консоли, не теряя их в журнале.
     *
     * Побочные эффекты: пишет в stdout/stderr и/или лог-файл.
     *
     * @param string $level Метка уровня (INFO/STEP/DEBUG/WARN/ERROR).
     * @param string $msg Текст сообщения.
     * @param resource|null $stream Поток консоли или null (только в файл).
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
