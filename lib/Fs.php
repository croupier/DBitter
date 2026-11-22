<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Файловые и ОС-утилиты: определение платформы, преобразование Windows-путей
 * в unix/WSL-вид (/mnt/c/...), поиск бинарников в PATH (which/hasBinary),
 * безопасное создание и рекурсивное удаление каталогов, форматирование
 * размеров. Используется транспортами (поиск mysql/wsl/ssh), Dump (пути,
 * временные файлы) и Command/Process (Fs::tempDir).
 */
final class Fs
{
    /**
     * Нормализованное имя ОС для конфигурации и логов.
     *
     * @return string 'windows', 'mac' или 'linux' (прочие семейства сводятся к linux).
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
     * Работаем ли мы на Windows — от этого зависят расширения бинарников
     * (.exe/.cmd/.bat), особенности env в proc_open и транспорт wsl.
     *
     * @return bool true на Windows.
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Меняет разделители "\" на "/" — базовое преобразование пути в unix-вид.
     *
     * @param string $path Исходный путь (возможно, Windows).
     * @return string Путь с прямыми слэшами.
     */
    public static function toUnix(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Преобразует Windows-путь в путь внутри WSL: "D:\dir\file" -> "/mnt/d/dir/file".
     * Нужно, чтобы MySQL/скрипты внутри WSL видели файлы Windows-диска.
     * Пути без префикса диска (уже unix) возвращаются без изменений.
     *
     * @param string $path Windows- или unix-путь.
     * @return string Путь в терминах файловой системы WSL.
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
     * Каталог временных файлов без завершающего слэша — сюда Command/Process
     * пишут захват вывода, а Dump распаковывает zstd.
     *
     * @return string Путь системного temp-каталога.
     */
    public static function tempDir(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\');
    }

    /**
     * Гарантирует существование каталога (создаёт рекурсивно при необходимости).
     *
     * Побочные эффекты: создаёт каталог и всех недостающих родителей.
     *
     * @param string $dir Путь каталога.
     * @throws \RuntimeException Если создать каталог не удалось.
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
     * Аналог `which`: ищет исполняемый файл по имени в дополнительных
     * каталогах и в PATH. На Windows перебирает типовые расширения
     * (.exe/.cmd/.bat и без расширения), на unix — точное имя.
     *
     * @param string $name Имя бинарника (mysql, wsl, zstd, ssh...).
     * @param array<int,string> $extraDirs Каталоги, проверяемые раньше PATH.
     * @return string|null Полный путь к найденному файлу или null.
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
     * Есть ли бинарник в PATH/доп. каталогах — используется doctor и
     * транспортами для проверки доступности mysql/wsl/docker/ssh.
     *
     * @param string $name Имя бинарника.
     * @param array<int,string> $extraDirs Дополнительные каталоги поиска.
     * @return bool true, если файл найден.
     */
    public static function hasBinary(string $name, array $extraDirs = []): bool
    {
        return self::which($name, $extraDirs) !== null;
    }

    /**
     * Рекурсивно удаляет каталог со всем содержимым (аналог rm -rf).
     *
     * Побочные эффекты: безвозвратно удаляет файлы и подкаталоги;
     * несуществующий каталог — не ошибка (тихий выход).
     *
     * @param string $dir Путь удаляемого каталога.
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
     * Переводит мегабайты (возможно, дробные — из настроек размера части
     * дампа) в целое число байт.
     *
     * @param float $mb Размер в МиБ.
     * @return int Размер в байтах (округлённый).
     */
    public static function bytes(float $mb): int
    {
        return (int) round($mb * 1024 * 1024);
    }

    /**
     * Человекочитаемый размер для логов и манифестов: B/KiB/MiB/GiB.
     * Байты — без дробной части, остальные единицы — с одним знаком.
     *
     * @param int $bytes Размер в байтах.
     * @return string Например, "1536 B", "1.5 KiB", "23.4 MiB".
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
