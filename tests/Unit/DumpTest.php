<?php

/**
 * Юнит-тесты DbTools\Lib\Dump: расширения/алгоритмы сжатия, разбиение
 * файла на части (splitFile) и склейку (joinFiles), sha256, манифесты
 * и latest.json, ротацию дампов (prune) и уникальность имён.
 * Закрывает регрессии split-фикса (файл меньше блока → один архив без
 * .part001; пустой хвостовой part) и prune-фикса (entries — массивы,
 * раньше было Array-to-string и файлы дампов не удалялись). Без БД.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Dump;
use PHPUnit\Framework\TestCase;

/**
 * Все файловые операции выполняются в одноразовом временном каталоге,
 * который создаётся в setUp и полностью убирается в tearDown.
 */
final class DumpTest extends TestCase
{
    /** Временный каталог для файлов конкретного теста. */
    private string $dir;

    /** Создаёт уникальный временный каталог (случайный hex — защита от коллизий). */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbt_dump_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    /** Удаляет все файлы теста и сам каталог — следов не остаётся. */
    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /**
     * Хелпер: создаёт файл с заданным содержимым в каталоге теста.
     *
     * @return string полный путь к созданному файлу
     */
    private function file(string $name, string $content): string
    {
        $p = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($p, $content);
        return $p;
    }

    /**
     * extension() и algoFromFile() — взаимно обратные: gzip ↔ .sql.gz,
     * zstd ↔ .sql.zst, none ↔ .sql. Важно для импорта: алгоритм
     * распаковки определяется по имени файла из манифеста.
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
     * Регрессия split <= блока: файл, целиком помещающийся в блок,
     * должен остаться ОДНИМ архивом с исходным именем (без суффикса
     * .partNNN) и не быть удалённым. Раньше создавался лишний .part001,
     * а оригинал исчезал — манифест и импорт ломались.
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
     * Граничный случай: размер файла РАВЕН блоку — тоже один файл,
     * разбиение не нужно (проверка строгой границы size <= partBytes).
     */
    public function testSplitExactlyOneBlockIsSingle(): void
    {
        $src = $this->file('dump.sql', str_repeat('A', 4));
        $parts = Dump::splitFile($src, 4);
        $this->assertCount(1, $parts);
        $this->assertSame($src, $parts[0]);
    }

    /**
     * Граничный случай: пустой (0 байт) файл не должен порождать
     * 0-байтный .partNNN — возвращается сам (пустой) оригинал.
     */
    public function testSplitZeroByteFileProducesNoParts(): void
    {
        $src = $this->file('dump.sql', '');
        $parts = Dump::splitFile($src, 4);
        $this->assertCount(1, $parts); // (пустой) оригинал, а не 0-байтный .partNNN
        $this->assertFileExists($src);
    }

    /**
     * Регрессия пустого хвостового part: при размере, кратном блоку,
     * последний цикл чтения даёт 0 байт — такой part обязан удаляться,
     * а не попадать в манифест. Плюс полный round-trip: split → join
     * должен побайтно восстановить исходное (случайное) содержимое.
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
     * sha256() считает контрольную сумму файла — эталонный вектор для
     * строки 'abc'. Суммы из манифеста проверяются при импорте.
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
     * Round-trip манифеста: write → read возвращает те же данные;
     * writeLatest/readLatest корректно хранят указатель на последний
     * манифест (latest.json), по которому импорт находит свежий дамп.
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
     * Регрессия prune: entries в манифесте — МАССИВЫ ['file'=>..., ...].
     * Раньше entry приводился к строке напрямую (Array-to-string conversion),
     * имя файла получалось мусорным и сами дампы НЕ удалялись — копились
     * вечно. Теперь prune обязан удалить и файлы данных, и манифесты
     * старых дампов, оставив keep=1 самых свежих (порядок по mtime).
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
     * baseName() уникален даже при двух вызовах в одну секунду
     * (иначе два экспорта подряд перезаписали бы друг друга) и содержит
     * читаемый префикс проект__транспорт__вид__.
     */
    public function testBaseNameIsUniquePerCall(): void
    {
        $a = Dump::baseName('prodom', 'wsl', 'full');
        $b = Dump::baseName('prodom', 'wsl', 'full');
        $this->assertNotSame($a, $b, 'same-second names must not collide');
        $this->assertStringContainsString('prodom__wsl__full__', $a);
    }
}
