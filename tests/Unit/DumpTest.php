<?php

/**
 * Unit tests for DbTools\Lib\Dump: compression extensions/algorithms, splitting
 * a file into parts (splitFile) and joining them back (joinFiles), sha256,
 * manifests and latest.json, dump rotation (prune), and name uniqueness.
 * Covers the split-fix regressions (file smaller than a block → a single archive
 * without .part001; empty trailing part) and the prune-fix regression (entries
 * are arrays — previously an Array-to-string error occurred and dump files were
 * never deleted). No DB involved.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Dump;
use PHPUnit\Framework\TestCase;

/**
 * All file operations run in a disposable temporary directory
 * created in setUp and fully removed in tearDown.
 */
final class DumpTest extends TestCase
{
    /** Temporary directory for the individual test's files. */
    private string $dir;

    /** Creates a unique temporary directory (random hex — collision protection). */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbt_dump_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    /** Deletes all of the test's files and the directory itself — no traces left. */
    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /**
     * Helper: creates a file with the given content in the test directory.
     *
     * @return string full path to the created file
     */
    private function file(string $name, string $content): string
    {
        $p = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($p, $content);
        return $p;
    }

    /**
     * extension() and algoFromFile() are mutual inverses: gzip ↔ .sql.gz,
     * zstd ↔ .sql.zst, none ↔ .sql. Important for import: the decompression
     * algorithm is determined from the file name in the manifest.
     */
    public function testExtensionAndAlgoRoundtrip(): void
    {
        $this->assertSame('.sql.gz', Dump::extension('gzip'));
        $this->assertSame('.sql.zst', Dump::extension('zstd'));
        $this->assertSame('.sql', Dump::extension('none'));
        $this->assertSame('gzip', Dump::algoFromFile('x.sql.gz'));
        $this->assertSame('zstd', Dump::algoFromFile('x.sql.zst'));
        $this->assertSame('none', Dump::algoFromFile('x.sql'));
    }

    /**
     * Split <= block regression: a file that fits entirely within one block
     * must remain a SINGLE archive with its original name (no .partNNN suffix)
     * and must not be deleted. Previously a redundant .part001 was created and
     * the original vanished — breaking the manifest and the import.
     */
    public function testSplitSmallerThanBlockKeepsSingleFile(): void
    {
        $src = $this->file('dump.sql', str_repeat('A', 100));
        $parts = Dump::splitFile($src, 1000);
        $this->assertCount(1, $parts);
        $this->assertSame($src, $parts[0]);
        $this->assertStringNotContainsString('.part', $parts[0]);
        $this->assertFileExists($src);
    }

    /**
     * Boundary case: file size EQUALS the block — still a single file,
     * no splitting needed (verifies the strict boundary size <= partBytes).
     */
    public function testSplitExactlyOneBlockIsSingle(): void
    {
        $src = $this->file('dump.sql', str_repeat('A', 4));
        $parts = Dump::splitFile($src, 4);
        $this->assertCount(1, $parts);
        $this->assertSame($src, $parts[0]);
    }

    /**
     * Boundary case: an empty (0-byte) file must not spawn a 0-byte .partNNN —
     * the (empty) original itself is returned.
     */
    public function testSplitZeroByteFileProducesNoParts(): void
    {
        $src = $this->file('dump.sql', '');
        $parts = Dump::splitFile($src, 4);
        $this->assertCount(1, $parts); // (пустой) оригинал, а не 0-байтный .partNNN
        $this->assertFileExists($src);
    }

    /**
     * Empty trailing part regression: when the size is an exact multiple of the
     * block, the final read loop yields 0 bytes — such a part must be deleted
     * instead of ending up in the manifest. Plus a full round-trip: split → join
     * must restore the original (random) content byte for byte.
     */
    public function testSplitAndJoinRoundtrip(): void
    {
        $content = random_bytes(1024);
        $src = $this->file('blob.bin', $content);
        $parts = Dump::splitFile($src, 300);
        $this->assertGreaterThan(1, count($parts));
        // Пустых частей быть не должно (в т.ч. хвостового 0-байтного).
        foreach ($parts as $p) {
            $this->assertGreaterThan(0, filesize($p));
        }
        $joined = $this->dir . DIRECTORY_SEPARATOR . 'joined.bin';
        Dump::joinFiles($parts, $joined);
        $this->assertSame($content, file_get_contents($joined));
    }

    /**
     * sha256() computes a file checksum — the reference vector for the
     * string 'abc'. Manifest checksums are verified during import.
     */
    public function testSha256(): void
    {
        $src = $this->file('abc.txt', 'abc');
        $this->assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            Dump::sha256($src)
        );
    }

    /**
     * Manifest round-trip: write → read returns the same data;
     * writeLatest/readLatest correctly store the pointer to the latest
     * manifest (latest.json), which the import uses to find the freshest dump.
     */
    public function testManifestAndLatestRoundtrip(): void
    {
        $data = ['kind' => 'full', 'files' => [['file' => 'a.sql.gz']]];
        $m = $this->dir . DIRECTORY_SEPARATOR . 'm.json';
        Dump::writeManifest($m, $data);
        $this->assertSame($data, Dump::readManifest($m));
        Dump::writeLatest($this->dir, 'm.json');
        $this->assertSame('m.json', Dump::readLatest($this->dir)['manifest']);
    }

    /**
     * Prune regression: entries in the manifest are ARRAYS ['file'=>..., ...].
     * Previously an entry was cast to a string directly (Array-to-string conversion),
     * producing a garbage file name, and the dumps themselves were NOT deleted —
     * piling up forever. Now prune must delete both the data files and the manifests
     * of old dumps, keeping the keep=1 freshest ones (ordered by mtime).
     */
    public function testPruneKeepsNNewest(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $name = 'old' . $i;
            $dataFile = $name . '.sql';
            file_put_contents($this->dir . DIRECTORY_SEPARATOR . $dataFile, 'x');
            $manifest = $this->dir . DIRECTORY_SEPARATOR . $name . '.json';
            Dump::writeManifest($manifest, ['files' => [['file' => $dataFile]]]);
            touch($manifest, time() + $i);       // детерминированный порядок по mtime
            touch($this->dir . DIRECTORY_SEPARATOR . $dataFile, time() + $i);
        }
        $removed = Dump::prune($this->dir, 1);    // оставить только самый свежий
        $this->assertSame(2, $removed);
        $this->assertFileExists($this->dir . '/old2.sql');
        $this->assertFileDoesNotExist($this->dir . '/old0.sql');
        $this->assertFileDoesNotExist($this->dir . '/old1.sql');
        $this->assertFileDoesNotExist($this->dir . '/old0.json');
    }

    /**
     * baseName() is unique even across two calls within the same second
     * (otherwise two consecutive exports would overwrite each other) and contains
     * a readable project__transport__kind__ prefix.
     */
    public function testBaseNameIsUniquePerCall(): void
    {
        $a = Dump::baseName('prodom', 'wsl', 'full');
        $b = Dump::baseName('prodom', 'wsl', 'full');
        $this->assertNotSame($a, $b, 'same-second names must not collide');
        $this->assertStringContainsString('prodom__wsl__full__', $a);
    }
}
