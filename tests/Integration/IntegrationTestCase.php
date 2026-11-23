<?php

/**
 * Base class for integration tests: they run the real CLI start.php
 * as a child process against a live MySQL. Transport and connection come from
 * the project's own config (config.php + config.local.php).
 * Disabled by default (DBT_IT != 1 → markTestSkipped) so the unit run
 * `composer test` never requires a database.
 */

declare(strict_types=1);

namespace DbTools\Tests\Integration;

use DbTools\Lib\Command;
use DbTools\Lib\Result;
use PHPUnit\Framework\TestCase;

/**
 * Shared infrastructure for integration tests.
 *
 * Key characteristics:
 * - the DBT_IT=1 gate in setUp(): without a live DB tests are skipped, not failed;
 * - each action runs in a SEPARATE PHP process (runTool), so the
 *   start.php/lib code inside the child process does NOT land in PHPUnit coverage —
 *   coverage is collected only in the test-runner process itself. This is the price
 *   of realism: the whole real CLI is verified, including exit codes.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Tool root (DBitter) — the path to start.php is built from it. */
    protected string $toolRoot;

    /**
     * Checks the DBT_IT=1 gate and computes the tool root.
     * Without the environment variable the test is marked skipped — the unit run
     * stays fully self-contained.
     */
    protected function setUp(): void
    {
        if (getenv('DBT_IT') !== '1') {
            $this->markTestSkipped('Set DBT_IT=1 to run integration tests against a live MySQL server.');
        }
        $this->toolRoot = dirname(__DIR__, 2);
    }

    /**
     * Runs one DBitter action as a child process and returns its Result
     * (exit code, stdout/stderr). PHP_BINARY is the same interpreter that
     * launched PHPUnit, so the PHP version in the test and in the tool match.
     *
     * @param list<string> $args e.g. ['export', '--kind=schema', '--dir=/tmp']
     */
    final protected function runTool(array $args): Result
    {
        $cmd = array_merge([PHP_BINARY, $this->toolRoot . '/start.php'], $args);
        return Command::capture($cmd);
    }
}
