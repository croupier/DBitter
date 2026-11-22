<?php

/**
 * Юнит-тесты файловых утилит DbTools\Lib\Fs: определение ОС, конвертация
 * путей Windows → Unix/WSL (буква диска → /mnt/<диск>), пересчёт MiB в
 * байты и человекочитаемые размеры, идемпотентное создание каталогов.
 * Критично для WSL-транспорта: неверный путь сломал бы передачу дампов
 * между Windows-хостом и Linux-гостем. Без БД.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Fs;
use PHPUnit\Framework\TestCase;

/**
 * Проверки кроссплатформенных хелперов — выполняются на любом хосте,
 * ассерты не зависят от текущей ОС (кроме согласованности os/isWindows).
 */
final class FsTest extends TestCase
{
    /**
     * os() возвращает одну из известных меток, а isWindows() строго
     * согласован с ней — эти метки используются в именах дампов и логике
     * транспортов.
     */
    public function testOsIsKnownLabel(): void
    {
        $this->assertContains(Fs::os(), ['windows', 'mac', 'linux']);
        $this->assertSame(Fs::os() === 'windows', Fs::isWindows());
    }

    /**
     * toUnix меняет обратные слеши на прямые (без смены диска) —
     * нормализация путей для конфигов и манифестов.
     */
    public function testToUnix(): void
    {
        $this->assertSame('a/b/c', Fs::toUnix('a\\b\\c'));
    }

    /**
     * toWsl отображает Windows-путь в WSL: буква диска (в любом регистре,
     * с обоими видами слешей) становится /mnt/<строчная буква>/... —
     * так дамп на D:\ виден внутри WSL как /mnt/d/...
     */
    public function testToWslMapsDriveLetter(): void
    {
        $this->assertSame('/mnt/d/x/y', Fs::toWsl('D:\\x\\y'));
        $this->assertSame('/mnt/c/Users/a', Fs::toWsl('C:/Users/a'));
    }

    /**
     * Уже POSIX-путь toWsl не трогает — двойная конвертация недопустима
     * (пути внутри WSL должны проходить как есть).
     */
    public function testToWslLeavesPosixPaths(): void
    {
        $this->assertSame('/tmp/z', Fs::toWsl('/tmp/z'));
    }

    /**
     * bytes() переводит MiB (в т.ч. дробные, --split=1.5) в байты;
     * human() форматирует размеры в двоичных единицах (KiB/MiB),
     * включая граничный ноль.
     */
    public function testBytesAndHuman(): void
    {
        $this->assertSame(1048576, Fs::bytes(1));
        $this->assertSame(1572864, Fs::bytes(1.5));
        $this->assertSame('0 B', Fs::human(0));
        $this->assertSame('2 KiB', Fs::human(2048));
        $this->assertSame('1 MiB', Fs::human(1048576));
    }

    /**
     * ensureDir идемпотентен: повторный вызов на существующем каталоге
     * не должен ни предупреждать, ни бросать исключение (его зовут перед
     * каждой записью дампа).
     */
    public function testEnsureDirIsIdempotent(): void
    {
        $dir = sys_get_temp_dir() . '/dbt_fs_test_' . bin2hex(random_bytes(3));
        Fs::ensureDir($dir);
        $this->assertDirectoryExists($dir);
        Fs::ensureDir($dir); // второй вызов не должен предупреждать или бросать
        rmdir($dir);
    }
}
