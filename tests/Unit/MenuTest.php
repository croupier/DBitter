<?php

/**
 * Unit tests for the interactive menu DbTools\Lib\Menu with stdin closed.
 * Menu reads STDIN directly, so the checks run in a separate PHP subprocess
 * with empty stdin (instant EOF): the test runner cannot hang waiting for
 * input. At the same time this covers the EOF-guard from review fix #6:
 * choice() must terminate instead of spinning in an endless loop.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Command;
use PHPUnit\Framework\TestCase;

/**
 * Menu reads STDIN directly, so the tests evaluate expressions in a subprocess
 * with empty stdin (immediate EOF). This both protects the test runner from
 * hanging and verifies the EOF-guard (review fix #6): choice() must terminate
 * instead of looping forever.
 */
final class MenuTest extends TestCase
{
    /**
     * Runs a PHP expression in a subprocess: php -r with the tool's bootstrap.
     * Command::capture with a third argument of '' feeds an empty line to stdin
     * and closes the pipe immediately → fgets(STDIN) gets EOF. If Menu looped on
     * EOF, the subprocess would hang forever — hence the subprocess isolation is
     * mandatory (inside the PHPUnit process the whole run would freeze).
     *
     * @return string trimmed stdout of the subprocess
     */
    private function evalAtEof(string $expr): string
    {
        $boot = var_export(dirname(__DIR__, 2) . '/lib/bootstrap.php', true);
        $code = "require {$boot}; echo {$expr};";
        $res = Command::capture([PHP_BINARY, '-r', $code], [], '');
        $this->assertTrue($res->ok(), 'subprocess failed: ' . $res->output());
        return trim($res->stdout);
    }

    /**
     * Regression #6: choice() WITHOUT a default must return the first key at EOF
     * and terminate. Previously ask() kept returning an empty string at EOF and
     * while(true) spun forever — interactive mode hung completely whenever stdin
     * was redirected/closed.
     */
    public function testChoiceWithoutDefaultTerminatesAtEof(): void
    {
        $out = $this->evalAtEof("DbTools\\Lib\\Menu::choice('t', ['a' => 'A', 'b' => 'B'])");
        $this->assertStringEndsWith('a', $out); // откат к первому ключу, без вечного цикла
    }

    /**
     * choice() with an explicit default returns that very default at EOF,
     * not the first key of the list.
     */
    public function testChoiceHonorsDefaultAtEof(): void
    {
        $out = $this->evalAtEof("DbTools\\Lib\\Menu::choice('t', ['a' => 'A', 'b' => 'B'], 'b')");
        $this->assertStringEndsWith('b', $out);
    }

    /**
     * confirm() at EOF returns the default that was passed in (both variants:
     * true and false) — it neither hangs nor "guesses" the answer.
     */
    public function testConfirmReturnsDefaultAtEof(): void
    {
        $this->assertStringEndsWith('true', $this->evalAtEof("var_export(DbTools\\Lib\\Menu::confirm('x', true), true)"));
        $this->assertStringEndsWith('false', $this->evalAtEof("var_export(DbTools\\Lib\\Menu::confirm('x', false), true)"));
    }

    /**
     * ask() at EOF returns the default string — the base building block
     * that confirm/choice are built on.
     */
    public function testAskReturnsDefaultAtEof(): void
    {
        $out = $this->evalAtEof("DbTools\\Lib\\Menu::ask('q', 'dflt')");
        $this->assertStringEndsWith('dflt', $out);
    }
}
