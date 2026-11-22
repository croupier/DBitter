<?php

/**
 * Юнит-тесты discovery проекта в DbTools\Lib\Config: поиск корня проекта
 * вверх от cwd по маркерам, пропуск каталога инструмента, остановка на
 * .git-границе, legacy-фолбэк, слоение профилей (проект поверх инструмента)
 * и дефолты dump-каталога/имени проекта от найденного корня. Без БД:
 * используется временное дерево каталогов и настоящий conf/const.php.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Config;
use DbTools\Lib\Fs;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет контракт discovery, на который опирается пакетный режим:
 * проект находится от cwd, а не от расположения инструмента, и проектный
 * профиль dbitter.php/dbitter.local.php перекрывает профиль инструмента.
 */
final class ConfigDiscoveryTest extends TestCase
{
    /** Временное дерево: base/tool — инструмент, base/proj — проект. */
    private string $base = '';

    /**
     * Готовит изолированное дерево и копирует настоящий const-экстрактор
     * в tool/conf/, чтобы тесты гоняли боевой код извлечения реквизитов.
     */
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/dbt_disc_' . bin2hex(random_bytes(3));
        mkdir($this->base . '/tool/conf', 0775, true);
        mkdir($this->base . '/proj', 0775, true);
        copy(dirname(__DIR__, 2) . '/conf/const.php', $this->base . '/tool/conf/const.php');
    }

    /** Удаляет временное дерево после каждого теста. */
    protected function tearDown(): void
    {
        if ($this->base !== '') {
            Fs::rrmdir($this->base);
        }
    }

    /** Путь к «корню инструмента» внутри временного дерева. */
    private function tool(): string
    {
        return $this->base . '/tool';
    }

    /** Путь к «корню проекта» внутри временного дерева. */
    private function proj(): string
    {
        return $this->base . '/proj';
    }

    /**
     * Пишет PHP-файл, возвращающий массив (профиль или const-конфиг).
     *
     * @param string $path Куда писать.
     * @param array<string,mixed> $data Возвращаемый массив.
     */
    private function putPhp(string $path, array $data): void
    {
        file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    }

    /**
     * Проект находится вверх от cwd: запуск из глубокого подкаталога
     * proj/sub/deep поднимается до proj по маркеру dbitter.php. Имя проекта
     * и дефолтный dump-каталог считаются от найденного корня, а не от tool.
     */
    public function testDiscoverProjectUpwardFromCwd(): void
    {
        mkdir($this->proj() . '/sub/deep', 0775, true);
        $this->putPhp($this->proj() . '/dbitter.php', [
            'conf' => 'const',
            'db' => ['name' => 'disc_db'],
        ]);
        $cfg = new Config($this->tool(), [], $this->proj() . '/sub/deep');
        $this->assertSame($this->proj(), $cfg->projectRoot());
        $this->assertSame('proj', $cfg->projectName());
        $this->assertSame('disc_db', $cfg->db()['name']);
        $this->assertSame($this->proj() . '/dump', $cfg->dumpDir());
    }

    /**
     * Каталог инструмента пропускается при обходе: даже с composer.json
     * и .git внутри tool он не становится «проектом» — поиск продолжается
     * вверх и находит proj по маркеру dbitter.php.
     */
    public function testToolRootIsSkippedDuringWalk(): void
    {
        // tool лежит внутри proj: proj/tool — типичная standalone-раскладка.
        $tool = $this->proj() . '/tool';
        mkdir($tool . '/conf', 0775, true);
        copy(dirname(__DIR__, 2) . '/conf/const.php', $tool . '/conf/const.php');
        $this->putPhp($tool . '/composer.json', []); // маркер-ловушка в tool (содержимое не важно)
        mkdir($tool . '/.git');
        $this->putPhp($this->proj() . '/dbitter.php', [
            'conf' => 'const',
            'db' => ['name' => 'parent_db'],
        ]);
        $cfg = new Config($tool, [], $tool);
        $this->assertSame($this->proj(), $cfg->projectRoot());
        $this->assertSame('parent_db', $cfg->db()['name']);
    }

    /**
     * .git — граница обхода: если маркеров проекта нет, поиск не уходит
     * за VCS-корень и Config откатывается к legacy-поведению
     * (project = dirname(tool), dump по умолчанию в tool/dump).
     */
    public function testGitBoundaryTriggersLegacyFallback(): void
    {
        mkdir($this->base . '/.git');
        $tool = $this->proj() . '/tool';
        mkdir($tool . '/conf', 0775, true);
        copy(dirname(__DIR__, 2) . '/conf/const.php', $tool . '/conf/const.php');
        // В proj нет ни одного маркера — обход доходит до base с .git и стопорится.
        $this->putPhp($this->proj() . '/db.php', ['db' => ['name' => 'fb_db']]);
        $cfg = new Config($tool, ['conf' => 'const', 'config' => $this->proj() . '/db.php'], $this->proj());
        $this->assertSame($this->proj(), $cfg->projectRoot()); // dirname(tool)
        $this->assertSame($tool . '/dump', $cfg->dumpDir());   // legacy-дефолт
    }

    /**
     * Слоение профилей: CLI > dbitter.local.php > dbitter.php (проект) >
     * config.local.php > config.php (инструмент). Проектный профиль
     * перекрывает инструментный, локальные файлы — свои основные.
     */
    public function testProfileLayersProjectOverTool(): void
    {
        $this->putPhp($this->tool() . '/config.php', ['conf' => 'const', 'dump' => ['kind' => 'schema', 'compress' => 'gzip']]);
        $this->putPhp($this->tool() . '/config.local.php', ['dump' => ['compress' => 'none']]);
        $this->putPhp($this->proj() . '/dbitter.php', ['dump' => ['kind' => 'data'], 'db' => ['name' => 'layer_db']]);
        $this->putPhp($this->proj() . '/dbitter.local.php', ['dump' => ['split' => 5]]);

        $cfg = new Config($this->tool(), [], $this->proj());
        $this->assertSame('data', $cfg->opt('kind', 'x'));    // проект поверх инструмента
        $this->assertSame('none', $cfg->opt('compress', 'x')); // local инструмента поверх его config.php
        $this->assertSame(5, $cfg->opt('split', 0));           // local проекта
        $withCli = new Config($this->tool(), ['kind' => 'full'], $this->proj());
        $this->assertSame('full', $withCli->opt('kind', 'x')); // CLI поверх всего
    }

    /**
     * Явный dump.dir в профиле важнее дефолта <project>/dump.
     */
    public function testExplicitDumpDirWinsOverProjectDefault(): void
    {
        $this->putPhp($this->proj() . '/dbitter.php', [
            'conf' => 'const',
            'db' => ['name' => 'dir_db'],
            'dump' => ['dir' => $this->base . '/custom-dumps'],
        ]);
        $cfg = new Config($this->tool(), [], $this->proj());
        $this->assertSame($this->base . '/custom-dumps', $cfg->dumpDir());
    }

    /**
     * const-экстрактор по умолчанию берёт реквизиты из dbitter.local.php
     * проекта (приоритетнее dbitter.php — там секреты), а не из профиля
     * инструмента.
     */
    public function testConstExtractorPrefersProjectLocalFile(): void
    {
        $this->putPhp($this->proj() . '/dbitter.php', ['conf' => 'const', 'db' => ['name' => 'base_db']]);
        $this->putPhp($this->proj() . '/dbitter.local.php', ['db' => ['name' => 'local_db', 'pass' => 's3cret']]);
        $cfg = new Config($this->tool(), [], $this->proj());
        $this->assertSame('local_db', $cfg->db()['name']);
        $this->assertSame('s3cret', $cfg->db()['pass']);
        $this->assertStringEndsWith('dbitter.local.php', $cfg->db()['source']);
    }
}
