<?php

/**
 * Юнит-тесты DbTools\Lib\Command: экранирование аргументов для bash,
 * сборка bash-строки с env-префиксом, маскировка секретов в логах и
 * слияние окружения для proc_open. Без запуска внешних команд и БД.
 * Закрывает регрессии маскировки коротких паролей и потери PATH при
 * передаче env-массива в proc_open (Windows).
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Command;
use PHPUnit\Framework\TestCase;

/**
 * Проверки сериализации/маскировки команд — критично для безопасности:
 * пароли не должны светиться в логах, а спецсимволы — ломать bash-строку.
 */
final class CommandTest extends TestCase
{
    /**
     * bashQuote оборачивает значение в одинарные кавычки; внутренняя
     * одинарная кавычка экранируется классическим bash-приёмом: закрыть
     * кавычку, вставить экранированную кавычку, открыть заново —
     * a'b превращается в 'a'\''b (конкатенация трёх кусков в bash).
     */
    public function testBashQuoteEscapesSingleQuotes(): void
    {
        $this->assertSame("'a'", Command::bashQuote('a'));
        $this->assertSame("'a'\\''b'", Command::bashQuote("a'b"));
    }

    /**
     * bashString собирает единую bash-строку: сначала env-присваивания
     * (MYSQL_PWD='...'), затем каждый аргумент в отдельных кавычках.
     * Так пароль доезжает до цели внутри `bash -c`, а не через argv хоста.
     */
    public function testBashStringFoldsEnvAndQuotesArgs(): void
    {
        $out = Command::bashString(['mysql', '-e', 'SELECT 1'], ['MYSQL_PWD' => 'p']);
        $this->assertSame("MYSQL_PWD='p' 'mysql' '-e' 'SELECT 1'", $out);
    }

    /**
     * mask() всегда прячет форму MYSQL_PWD='...' — даже без списка секретов:
     * это основной канал передачи пароля во всех транспортах.
     */
    public function testMaskHidesMysqlPwdEnv(): void
    {
        $out = Command::mask("MYSQL_PWD='secret' mysqldump db", []);
        $this->assertStringNotContainsString('secret', $out);
        $this->assertStringContainsString("MYSQL_PWD='***'", $out);
    }

    /**
     * Длинный секрет (>= 4 символов) маскируется и как «голая» подстрока —
     * например, в тексте сообщения об ошибке соединения.
     */
    public function testMaskLongSecretAsSubstring(): void
    {
        $out = Command::mask('connecting wbyWAHC7 here', ['wbyWAHC7']);
        $this->assertStringNotContainsString('wbyWAHC7', $out);
        $this->assertStringContainsString('***', $out);
    }

    /**
     * Регрессия: односимвольный пароль нельзя маскировать как подстроку —
     * раньше буква 'a' из пароля превращала "bash" в "b***sh" и портила
     * весь вывод. Короткие секреты прячутся только в кавычках/делимитированных
     * формах ('a', --password=a, -pa), но не внутри обычных слов.
     */
    public function testMaskShortSecretOnlyInDelimitedForms(): void
    {
        $this->assertSame('bash /mnt/d/ab.sh', Command::mask('bash /mnt/d/ab.sh', ['a']));
        $masked = Command::mask("CREATE USER 'u'@'h' IDENTIFIED BY 'a'", ['a']);
        $this->assertStringContainsString("IDENTIFIED BY '***'", $masked);
        $this->assertStringNotContainsString("BY 'a'", $masked);
    }

    /**
     * Пустой override окружения → null: proc_open наследует окружение
     * родителя как есть (без массива вообще).
     */
    public function testMergeEnvEmptyReturnsNull(): void
    {
        $this->assertNull(Command::mergeEnv([]));
    }

    /**
     * Регрессия Windows: proc_open с env-массивом ПОЛНОСТЬЮ ЗАМЕНЯЕТ
     * окружение ребёнка, а не дополняет. Без слияния терялись PATH/SystemRoot
     * и внешние бинарники переставали запускаться. mergeEnv обязан добавить
     * MYSQL_PWD поверх унаследованного окружения, сохранив PATH.
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
     * display() приводит команду к строке для логов: массив склеивается
     * пробелами, строка возвращается без изменений.
     */
    public function testDisplay(): void
    {
        $this->assertSame('a b', Command::display(['a', 'b']));
        $this->assertSame('a b', Command::display('a b'));
    }
}
