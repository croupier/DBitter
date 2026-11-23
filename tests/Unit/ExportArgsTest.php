<?php

/**
 * Unit tests for building mysqldump/mysql arguments (ExportDump::buildArgs and
 * Action::clientBase). They verify the flags for each dump kind, the pass-through
 * of where/ignore-table/extra/tables, and two security invariants:
 * the password NEVER ends up in argv (only via the MYSQL_PWD env var),
 * and mysql_extra applies only to the mysql client, not to mysqldump.
 * No commands are run — the config comes from the tests/_data/const_db.php fixture.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Action\Action;
use DbTools\Action\ExportDump;
use DbTools\Lib\Config;
use PHPUnit\Framework\TestCase;

/**
 * argv checks without execution: a deterministic config (conf=const,
 * target=local) allows asserting the exact flag set on any machine.
 */
final class ExportArgsTest extends TestCase
{
    /**
     * Builds a Config on the deterministic const_db.php fixture
     * (transport=local, to stay independent of the machine's WSL/SSH settings)
     * and applies the given CLI overrides.
     *
     * @param array<string,mixed> $cli additional CLI options for the test
     */
    private function cfg(array $cli = []): Config
    {
        return new Config(dirname(__DIR__, 2), array_merge([
            'conf' => 'const',
            'config' => __DIR__ . '/../_data/const_db.php',
            'target' => 'local',
        ], $cli));
    }

    /**
     * kind=schema: structure only (--no-data), without --single-transaction
     * (no data is read — no consistent snapshot needed); the DB name
     * from the config is present in argv.
     */
    public function testSchemaKindUsesNoData(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg(), 'schema');
        $this->assertContains('--no-data', $argv);
        $this->assertNotContains('--single-transaction', $argv);
        $this->assertContains('it_database', $argv);
    }

    /**
     * kind=data: data only (--no-create-info), CREATE TABLE statements are
     * not exported; --no-data must be absent (mutually exclusive flags).
     */
    public function testDataKindUsesNoCreateInfo(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg(), 'data');
        $this->assertContains('--no-create-info', $argv);
        $this->assertNotContains('--no-data', $argv);
    }

    /**
     * kind=full: a consistent InnoDB snapshot (--single-transaction),
     * --no-tablespaces (does not require the PROCESS privilege) and --hex-blob
     * (binary data in hex — safe on import).
     */
    public function testFullKindUsesSingleTransaction(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg(), 'full');
        $this->assertContains('--single-transaction', $argv);
        $this->assertContains('--no-tablespaces', $argv);
        $this->assertContains('--hex-blob', $argv);
    }

    /**
     * Pass-through of user options: --where, repeatable --ignore-table,
     * repeatable --extra (raw mysqldump flags) and a comma-separated table
     * list (whitespace around names is trimmed).
     */
    public function testWhereIgnoreTableExtraAndTables(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg([
            'where' => 'id > 10',
            'ignore-table' => ['it_database.wp_logs'],
            'extra' => ['--column-statistics=0'],
            'tables' => 'wp_posts, wp_options',
        ]), 'full');
        $this->assertContains('--where=id > 10', $argv);
        $this->assertContains('--ignore-table=it_database.wp_logs', $argv);
        $this->assertContains('--column-statistics=0', $argv);
        $this->assertContains('wp_posts', $argv);
        $this->assertContains('wp_options', $argv);
    }

    /**
     * routines/triggers/events=false switches must remove the
     * corresponding flags from argv (they are enabled by default in the profile).
     */
    public function testRoutinesTriggersEventsToggle(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg([
            'routines' => false, 'triggers' => false, 'events' => false,
        ]), 'full');
        $this->assertNotContains('--routines', $argv);
        $this->assertNotContains('--triggers', $argv);
        $this->assertNotContains('--events', $argv);
    }

    /**
     * Regression (review fix #4): mysql_extra holds mysql client flags
     * (e.g. --skip-column-names); they must NOT end up in mysqldump's argv,
     * since mysqldump does not understand them and would fail.
     * clientBase is protected — invoked via reflection with dump=false/true.
     */
    public function testMysqlExtraAppliesToClientNotDump(): void
    {
        $cfg = $this->cfg(['mysql_extra' => ['--skip-column-names']]);
        $m = new \ReflectionMethod(Action::class, 'clientBase');
        /** @var list<string> $client */
        $client = $m->invoke(new ExportDump(), $cfg, false);
        /** @var list<string> $dump */
        $dump = $m->invoke(new ExportDump(), $cfg, true);
        $this->assertContains('--skip-column-names', $client);
        $this->assertNotContains('--skip-column-names', $dump);
    }

    /**
     * Security invariant: the password is never put into argv —
     * it would be visible in the process list to any user on the system.
     * The password is passed only via the MYSQL_PWD environment variable;
     * the base argv has --user but not the fixture's password value.
     */
    public function testClientBaseNeverPutsPasswordInArgv(): void
    {
        $m = new \ReflectionMethod(Action::class, 'clientBase');
        /** @var list<string> $client */
        $client = $m->invoke(new ExportDump(), $this->cfg(), false);
        $this->assertNotContains('it_secret_pw', $client);
        $this->assertContains('--user=it_user', $client);
    }
}
