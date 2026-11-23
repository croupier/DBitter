<?php

/**
 * Integration smoke test of the `doctor` action against a live MySQL.
 * Verifies that the end-to-end chain works: argv parsing →
 * Config → transport (local/wsl/...) → the mysql client → the server.
 * Catches transport-launch and connection regressions that unit tests
 * (without running commands) fundamentally cannot see. Requires DBT_IT=1.
 */

declare(strict_types=1);

namespace DbTools\Tests\Integration;

/**
 * Checks the `doctor` diagnostics: all environment checks must pass
 * on a correctly configured machine (clients available, server responding).
 */
final class DoctorTest extends IntegrationTestCase
{
    /**
     * doctor exits with code 0 and prints the "All checks passed" summary.
     * Regression: a broken transport/connection would produce FAIL lines
     * and a nonzero exit code — the test highlights it immediately (with doctor's output).
     */
    public function testDoctorReportsAllChecksPassing(): void
    {
        $res = $this->runTool(['doctor']);
        $this->assertTrue($res->ok(), 'doctor failed: ' . $res->output());
        $this->assertStringContainsString('All checks passed', $res->output());
    }
}
