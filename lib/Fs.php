<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Filesystem and OS utilities: platform detection, converting Windows paths
 * to unix/WSL form (/mnt/c/...), locating binaries in PATH (which/hasBinary),
 * safe directory creation and recursive removal, size formatting. Used by
 * the transports (finding mysql/wsl/ssh), Dump (paths, temp files)
 * and Command/Process (Fs::tempDir).
 */
final class Fs
{
    /**
     * Normalized OS name for configuration and logs.
     *
     * @return string 'windows', 'mac' or 'linux' (other families are mapped to linux).
     */
    public static function os(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'mac',
            default => 'linux',
        };
    }

    /**
     * Whether we are running on Windows — this determines binary extensions
     * (.exe/.cmd/.bat), env specifics in proc_open and the wsl transport.
     *
     * @return bool true on Windows.
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Replaces "\" separators with "/" — the basic path conversion to unix form.
     *
     * @param string $path Source path (possibly a Windows one).
     * @return string Path with forward slashes.
     */
    public static function toUnix(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Converts a Windows path to a path inside WSL: "D:\dir\file" -> "/mnt/d/dir/file".
     * Needed so MySQL/scripts inside WSL can see files on the Windows drive.
     * Paths without a drive prefix (already unix) are returned unchanged.
     *
     * @param string $path A Windows or unix path.
     * @return string Path in terms of the WSL filesystem.
     */
    public static function toWsl(string $path): string
    {
        $path = self::toUnix($path);

        // Буква диска в WSL монтыруется в нижнем регистре: C:/ -> /mnt/c/.
        return preg_replace_callback(
            '#^([A-Za-z]):/#',
            static fn(array $m): string => '/mnt/' . strtolower($m[1]) . '/',
            $path
        ) ?? $path;
    }

    /**
     * Temporary file directory without a trailing slash — Command/Process
     * write captured output here, and Dump unpacks zstd into it.
     *
     * @return string Path to the system temp directory.
     */
    public static function tempDir(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\');
    }

    /**
     * Ensures a directory exists (creating it recursively when needed).
     *
     * Side effects: creates the directory and all missing parents.
     *
     * @param string $dir Directory path.
     * @throws \RuntimeException If the directory could not be created.
     */
    public static function ensureDir(string $dir): void
    {
        // Повторная проверка is_dir после неудачного mkdir страхует от гонки:
        // параллельный процесс мог успеть создать каталог между проверками.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    /**
     * Analogue of `which`: looks for an executable by name in the additional
     * directories and in PATH. On Windows it iterates over typical extensions
     * (.exe/.cmd/.bat and no extension), on unix — the exact name.
     *
     * @param string $name Binary name (mysql, wsl, zstd, ssh...).
     * @param array<int,string> $extraDirs Directories checked before PATH.
     * @return string|null Full path to the found file or null.
     */
    public static function which(string $name, array $extraDirs = []): ?string
    {
        $exts = self::isWindows() ? ['.exe', '.cmd', '.bat', ''] : [''];
        $dirs = array_merge($extraDirs, explode(PATH_SEPARATOR, (string) getenv('PATH')));
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($exts as $ext) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * Whether a binary is available in PATH/extra directories — used by doctor
     * and the transports to check the availability of mysql/wsl/docker/ssh.
     *
     * @param string $name Binary name.
     * @param array<int,string> $extraDirs Additional search directories.
     * @return bool true if the file was found.
     */
    public static function hasBinary(string $name, array $extraDirs = []): bool
    {
        return self::which($name, $extraDirs) !== null;
    }

    /**
     * Recursively removes a directory with all its contents (analogue of rm -rf).
     *
     * Side effects: irrevocably deletes files and subdirectories;
     * a nonexistent directory is not an error (silent return).
     *
     * @param string $dir Path of the directory to delete.
     */
    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            // Пропускаем ссылки на сам каталог и родителя, иначе — бесконечная рекурсия.
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Converts megabytes (possibly fractional — from dump chunk size settings)
     * to a whole number of bytes.
     *
     * @param float $mb Size in MiB.
     * @return int Size in bytes (rounded).
     */
    public static function bytes(float $mb): int
    {
        return (int) round($mb * 1024 * 1024);
    }

    /**
     * Human-readable size for logs and manifests: B/KiB/MiB/GiB.
     * Bytes are shown without a fractional part, other units with one decimal.
     *
     * @param int $bytes Size in bytes.
     * @return string For example, "1536 B", "1.5 KiB", "23.4 MiB".
     */
    public static function human(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return round($val, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
