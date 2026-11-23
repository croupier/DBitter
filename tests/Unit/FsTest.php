<?php

/**
 * Unit tests for the file utilities in DbTools\Lib\Fs: OS detection, Windows →
 * Unix/WSL path conversion (drive letter → /mnt/<drive>), MiB-to-bytes
 * conversion and human-readable sizes, idempotent directory creation.
 * Critical for the WSL transport: a wrong path would break dump transfer
 * between the Windows host and the Linux guest. No DB.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Fs;
use PHPUnit\Framework\TestCase;

/**
 * Checks for the cross-platform helpers — they run on any host, and the
 * assertions do not depend on the current OS (except os/isWindows consistency).
 */
final class FsTest extends TestCase
{
    /**
     * os() returns one of the known labels, and isWindows() is strictly
     * consistent with it — these labels are used in dump names and transport
     * logic.
     */
    public function testOsIsKnownLabel(): void
    {
        $this->assertContains(Fs::os(), ['windows', 'mac', 'linux']);
        $this->assertSame(Fs::os() === 'windows', Fs::isWindows());
    }

    /**
     * toUnix turns backslashes into forward ones (without changing the drive) —
     * path normalization for configs and manifests.
     */
    public function testToUnix(): void
    {
        $this->assertSame('a/b/c', Fs::toUnix('a\\b\\c'));
    }

    /**
     * toWsl maps a Windows path into WSL: the drive letter (any case, either
     * slash style) becomes /mnt/<lowercase letter>/... — this is how a dump on
     * D:\ appears inside WSL as /mnt/d/...
     */
    public function testToWslMapsDriveLetter(): void
    {
        $this->assertSame('/mnt/d/x/y', Fs::toWsl('D:\\x\\y'));
        $this->assertSame('/mnt/c/Users/a', Fs::toWsl('C:/Users/a'));
    }

    /**
     * toWsl leaves an already-POSIX path untouched — double conversion is
     * unacceptable (paths inside WSL must pass through as is).
     */
    public function testToWslLeavesPosixPaths(): void
    {
        $this->assertSame('/tmp/z', Fs::toWsl('/tmp/z'));
    }

    /**
     * bytes() converts MiB (including fractional values, --split=1.5) to bytes;
     * human() formats sizes in binary units (KiB/MiB), including the boundary
     * zero.
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
     * ensureDir is idempotent: calling it again on an existing directory must
     * neither warn nor throw (it is invoked before every dump write).
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
