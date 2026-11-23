<?php

/**
 * Unit tests for project discovery in DbTools\Lib\Config: locating the project
 * root upward from cwd via markers, skipping the tool's own directory, stopping
 * at the .git boundary, the legacy fallback, profile layering (project on top of
 * tool), and the dump-directory/project-name defaults derived from the discovered
 * root. No DB involved: a temporary directory tree and the real conf/const.php
 * are used.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Config;
use DbTools\Lib\Fs;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the discovery contract the package mode relies on:
 * the project is located from cwd, not from the tool's location, and the project
 * profile dbitter.php/dbitter.local.php overrides the tool profile.
 */
final class ConfigDiscoveryTest extends TestCase
{
    /** Temporary tree: base/tool is the tool, base/proj is the project. */
    private string $base = '';

    /**
     * Prepares an isolated tree and copies the real const extractor
     * into tool/conf/ so the tests exercise the production credential-extraction code.
     */
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/dbt_disc_' . bin2hex(random_bytes(3));
        mkdir($this->base . '/tool/conf', 0775, true);
        mkdir($this->base . '/proj', 0775, true);
        copy(dirname(__DIR__, 2) . '/conf/const.php', $this->base . '/tool/conf/const.php');
    }

    /** Removes the temporary tree after each test. */
    protected function tearDown(): void
    {
        if ($this->base !== '') {
            Fs::rrmdir($this->base);
        }
    }

    /** Path to the "tool root" inside the temporary tree. */
    private function tool(): string
    {
        return $this->base . '/tool';
    }

    /** Path to the "project root" inside the temporary tree. */
    private function proj(): string
    {
        return $this->base . '/proj';
    }

    /**
     * Writes a PHP file that returns an array (a profile or a const config).
     *
     * @param string $path Where to write.
     * @param array<string,mixed> $data The array to return.
     */
    private function putPhp(string $path, array $data): void
    {
        file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    }

    /**
     * The project is found upward from cwd: launching from the deep subdirectory
     * proj/sub/deep walks up to proj via the dbitter.php marker. The project name
     * and the default dump directory are computed from the discovered root, not from tool.
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
     * The tool directory is skipped during the walk: even with composer.json
     * and .git inside tool, it does not become the "project" — the search keeps
     * going upward and finds proj via the dbitter.php marker.
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
     * .git is a walk boundary: when no project markers are found, the search does
     * not go past the VCS root and Config falls back to legacy behavior
     * (project = dirname(tool), default dump in tool/dump).
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
     * Profile layering: CLI > dbitter.local.php > dbitter.php (project) >
     * config.local.php > config.php (tool). The project profile overrides
     * the tool profile, and local files override their main counterparts.
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
     * An explicit dump.dir in the profile takes precedence over the <project>/dump default.
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
     * The const extractor by default takes credentials from the project's
     * dbitter.local.php (takes precedence over dbitter.php — secrets live there),
     * not from the tool profile.
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
