<?php

/**
 * Unit tests for DbTools\Lib\Command: escaping arguments for bash,
 * building bash strings with an env prefix, masking secrets in logs, and
 * merging the environment for proc_open. No external commands or DBs are
 * run. Covers regressions for short-password masking and PATH loss when
 * passing an env array to proc_open (Windows).
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Command;
use PHPUnit\Framework\TestCase;

/**
 * Checks for command serialization/masking — critical for security:
 * passwords must not leak into logs, and special characters must not break the bash string.
 */
final class CommandTest extends TestCase
{
    /**
     * bashQuote wraps the value in single quotes; an inner single quote is
     * escaped with the classic bash trick: close the quote, insert an
     * escaped quote, reopen — a'b becomes 'a'\''b (concatenation of three
     * pieces in bash).
     */
    public function testBashQuoteEscapesSingleQuotes(): void
    {
        $this->assertSame("'a'", Command::bashQuote('a'));
        $this->assertSame("'a'\\''b'", Command::bashQuote("a'b"));
    }

    /**
     * bashString assembles a single bash string: first the env assignments
     * (MYSQL_PWD='...'), then each argument in its own quotes. This way the
     * password reaches the target inside `bash -c` rather than via the host's argv.
     */
    public function testBashStringFoldsEnvAndQuotesArgs(): void
    {
        $out = Command::bashString(['mysql', '-e', 'SELECT 1'], ['MYSQL_PWD' => 'p']);
        $this->assertSame("MYSQL_PWD='p' 'mysql' '-e' 'SELECT 1'", $out);
    }

    /**
     * mask() always hides the MYSQL_PWD='...' form — even without a secret
     * list: it is the primary password delivery channel in all transports.
     */
    public function testMaskHidesMysqlPwdEnv(): void
    {
        $out = Command::mask("MYSQL_PWD='secret' mysqldump db", []);
        $this->assertStringNotContainsString('secret', $out);
        $this->assertStringContainsString("MYSQL_PWD='***'", $out);
    }

    /**
     * A long secret (>= 4 characters) is also masked as a "bare" substring —
     * for example, in the text of a connection error message.
     */
    public function testMaskLongSecretAsSubstring(): void
    {
        $out = Command::mask('connecting wbyWAHC7 here', ['wbyWAHC7']);
        $this->assertStringNotContainsString('wbyWAHC7', $out);
        $this->assertStringContainsString('***', $out);
    }

    /**
     * Regression: a one-character password must not be masked as a substring —
     * previously the 'a' from the password turned "bash" into "b***sh" and
     * ruined all output. Short secrets are hidden only in quoted/delimited
     * forms ('a', --password=a, -pa), but not inside regular words.
     */
    public function testMaskShortSecretOnlyInDelimitedForms(): void
    {
        $this->assertSame('bash /mnt/d/ab.sh', Command::mask('bash /mnt/d/ab.sh', ['a']));
        $masked = Command::mask("CREATE USER 'u'@'h' IDENTIFIED BY 'a'", ['a']);
        $this->assertStringContainsString("IDENTIFIED BY '***'", $masked);
        $this->assertStringNotContainsString("BY 'a'", $masked);
    }

    /**
     * An empty environment override → null: proc_open inherits the parent's
     * environment as is (no array passed at all).
     */
    public function testMergeEnvEmptyReturnsNull(): void
    {
        $this->assertNull(Command::mergeEnv([]));
    }

    /**
     * Windows regression: proc_open with an env array COMPLETELY REPLACES the
     * child environment instead of augmenting it. Without merging, PATH/SystemRoot
     * were lost and external binaries stopped launching. mergeEnv must add
     * MYSQL_PWD on top of the inherited environment, preserving PATH.
     */
    public function testMergeEnvInheritsParentEnvironment(): void
    {
        $merged = Command::mergeEnv(['DBT_PROBE' => '1']);
        $this->assertIsArray($merged);
        $this->assertSame('1', $merged['DBT_PROBE']);
        // Переопределение не должно затирать унаследованное окружение.
        // Имя PATH на Windows может быть в любом регистре (Path/PATH/path).
        $hasPath = isset($merged['PATH']) || isset($merged['Path']) || isset($merged['path']);
        $this->assertTrue($hasPath, 'inherited PATH must survive mergeEnv()');
    }

    /**
     * display() reduces a command to a string for logging: an array is joined
     * with spaces, a string is returned unchanged.
     */
    public function testDisplay(): void
    {
        $this->assertSame('a b', Command::display(['a', 'b']));
        $this->assertSame('a b', Command::display('a b'));
    }
}
