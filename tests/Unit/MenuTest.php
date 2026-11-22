<?php

/**
 * Юнит-тесты интерактивного меню DbTools\Lib\Menu при закрытом stdin.
 * Menu читает STDIN напрямую, поэтому проверки выполняются в отдельном
 * PHP-сабпроцессе с пустым stdin (мгновенный EOF): тест-раннер не может
 * зависнуть на ожидании ввода. Одновременно это проверка EOF-guard из
 * фикса ревью #6: choice() обязан завершаться, а не крутить вечный цикл.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Command;
use PHPUnit\Framework\TestCase;

/**
 * Menu читает STDIN напрямую, поэтому тесты гоняют выражения в сабпроцессе
 * с пустым stdin (немедленный EOF). Это и защита от зависания тест-раннера,
 * и проверка EOF-guard (фикс ревью #6): choice() должен завершаться,
 * а не зацикливаться навсегда.
 */
final class MenuTest extends TestCase
{
    /**
     * Выполняет PHP-выражение в сабпроцессе: php -r с бутстрапом инструмента.
     * Command::capture с третьим аргументом '' подаёт на stdin пустую строку
     * и сразу закрывает пайп → fgets(STDIN) получает EOF. Если бы Menu
     * зациклился на EOF, сабпроцесс завис бы навсегда — поэтому изоляция
     * сабпроцессом обязательна (в процессе PHPUnit завис бы весь прогон).
     *
     * @return string trimmed stdout сабпроцесса
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
     * Регрессия #6: choice() БЕЗ default на EOF обязан вернуть первый ключ
     * и завершиться. Раньше ask() на EOF вечно возвращал пустую строку и
     * while(true) крутился бесконечно — интерактивный режим вис намертво
     * при перенаправленном/закрытом stdin.
     */
    public function testChoiceWithoutDefaultTerminatesAtEof(): void
    {
        $out = $this->evalAtEof("DbTools\\Lib\\Menu::choice('t', ['a' => 'A', 'b' => 'B'])");
        $this->assertStringEndsWith('a', $out); // откат к первому ключу, без вечного цикла
    }

    /**
     * choice() с заданным default на EOF возвращает именно default,
     * а не первый ключ списка.
     */
    public function testChoiceHonorsDefaultAtEof(): void
    {
        $out = $this->evalAtEof("DbTools\\Lib\\Menu::choice('t', ['a' => 'A', 'b' => 'B'], 'b')");
        $this->assertStringEndsWith('b', $out);
    }

    /**
     * confirm() на EOF возвращает переданный default (оба варианта:
     * true и false) — не зависает и не «угадывает» ответ.
     */
    public function testConfirmReturnsDefaultAtEof(): void
    {
        $this->assertStringEndsWith('true', $this->evalAtEof("var_export(DbTools\\Lib\\Menu::confirm('x', true), true)"));
        $this->assertStringEndsWith('false', $this->evalAtEof("var_export(DbTools\\Lib\\Menu::confirm('x', false), true)"));
    }

    /**
     * ask() на EOF возвращает default-строку — базовый кирпич,
     * на котором стоят confirm/choice.
     */
    public function testAskReturnsDefaultAtEof(): void
    {
        $out = $this->evalAtEof("DbTools\\Lib\\Menu::ask('q', 'dflt')");
        $this->assertStringEndsWith('dflt', $out);
    }
}
