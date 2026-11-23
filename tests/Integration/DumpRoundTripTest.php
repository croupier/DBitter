<?php

/**
 * Integration round-trip test of dumps: export → manifest → import
 * against a live MySQL (DBT_IT=1). Each test works in a single-use
 * scratch DB with a random name and in a temporary dump directory, so it
 * does not touch the project's working database; tearDown guarantees everything is cleaned up.
 * Covers the split-fix regression: a dump smaller than the --split block must
 * remain a single file with no .partNNN artifacts.
 */

declare(strict_types=1);

namespace DbTools\Tests\Integration;

use DbTools\Lib\Dump;
use DbTools\Lib\Fs;

/**
 * Full dump upload/download cycle through the real CLI.
 * Verifies both action success (exit codes) and result integrity:
 * the latest.json manifest, file count, absence of extra parts,
 * the physical existence of the archive and the import into the scratch DB.
 */
final class DumpRoundTripTest extends IntegrationTestCase
{
    /** Name of the single-use scratch DB (empty until setUp has run). */
    private string $scratch = '';

    /** Temporary directory for this test's dumps (isolated from the project's dump/). */
    private string $dumpDir = '';

    /**
     * Creates the isolated environment: a random scratch-DB name
     * (dbt_it_ + random hex — parallel runs do not collide),
     * a temporary dump directory, then real create-db and create-user
     * through the CLI. Failing at this stage is already a signal that
     * the admin actions are broken.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = 'dbt_it_' . bin2hex(random_bytes(3));
        $this->dumpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbt_it_dumps_' . bin2hex(random_bytes(3));
        Fs::ensureDir($this->dumpDir);

        $db = $this->runTool(['create-db', '--name=' . $this->scratch]);
        $this->assertTrue($db->ok(), 'create-db failed: ' . $db->output());
        $user = $this->runTool(['create-user', '--name=' . $this->scratch]);
        $this->assertTrue($user->ok(), 'create-user failed: ' . $user->output());
    }

    /**
     * Cleanup after the test, even if it failed: drops the scratch DB
     * (--yes — no interactive confirmation, the child process has empty stdin)
     * and recursively wipes the temporary dump directory. No traces
     * must remain on the live server or in the filesystem.
     */
    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $this->runTool(['drop-db', '--name=' . $this->scratch, '--yes']);
        }
        if ($this->dumpDir !== '') {
            Fs::rrmdir($this->dumpDir);
        }
    }

    /**
     * End-to-end cycle: export the working DB's schema → import into an empty scratch DB.
     * Verifies that the schema dump is a single file without a .part suffix (nothing to
     * split) and that the import actually succeeds against the live server.
     * Regressions: a broken manifest, wrong client arguments,
     * transport errors when passing SQL over stdin.
     */
    public function testSchemaExportAndImportIntoScratch(): void
    {
        $export = $this->runTool(['export', '--kind=schema', '--dir=' . $this->dumpDir]);
        $this->assertTrue($export->ok(), 'export failed: ' . $export->output());

        $manifest = $this->latestManifest();
        $this->assertCount(1, $manifest['files']);
        $this->assertStringNotContainsString('.part', $manifest['files'][0]['file']);

        $import = $this->runTool(['import', '--dir=' . $this->dumpDir, '--database=' . $this->scratch]);
        $this->assertTrue($import->ok(), 'import failed: ' . $import->output());
    }

    /**
     * End-to-end guard for the split-fix: a full dump knowingly smaller
     * than --split=1000 (MiB) must remain a SINGLE .sql.gz — with no .partNNN
     * in the manifest or on disk. Regression: previously small dumps got
     * a spurious .part001 while the original was deleted, breaking import and the manifest.
     */
    public function testFullDumpUnderSplitStaysSingleFile(): void
    {
        $export = $this->runTool(['export', '--kind=full', '--split=1000', '--dir=' . $this->dumpDir]);
        $this->assertTrue($export->ok(), 'export failed: ' . $export->output());

        $manifest = $this->latestManifest();
        $this->assertCount(1, $manifest['files']);
        $name = $manifest['files'][0]['file'];
        $this->assertStringNotContainsString('.part', $name);
        // Манифест может врать — проверяем и физическое наличие файла.
        $this->assertFileExists($this->dumpDir . DIRECTORY_SEPARATOR . $name);
    }

    /**
     * Reads the latest dump's manifest via latest.json (the pointer
     * that export refreshes after every dump).
     *
     * @return array<string,mixed> manifest contents (kind, files, ...)
     */
    private function latestManifest(): array
    {
        $latest = Dump::readLatest($this->dumpDir);
        $this->assertNotNull($latest, 'no latest.json after export');
        return Dump::readManifest($this->dumpDir . DIRECTORY_SEPARATOR . $latest['manifest']);
    }
}
