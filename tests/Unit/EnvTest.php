<?php

/**
 * Юнит-тесты парсера .env (DbTools\Lib\Env::parse): префикс export,
 * одинарные/двойные кавычки, escape-последовательности, комментарии
 * и пустые значения. Важно для экстракторов env/laravel: некорректный
 * разбор пароля с '#' или '\n' внутри кавычек ломал бы подключение к БД.
 * Работает на временных файлах, БД не нужна.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Lib\Env;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет разбор всех типичных форм строк .env и безопасное поведение
 * на отсутствующем файле (пустой массив вместо исключения).
 */
final class EnvTest extends TestCase
{
    /** Путь к временному .env текущего теста (убирается в tearDown). */
    private string $file = '';

    /** Удаляет временный .env, если тест его создал. */
    protected function tearDown(): void
    {
        if ($this->file !== '') {
            @unlink($this->file);
        }
    }

    /**
     * Полный набор форматов: export-префикс снимается; двойные кавычки
     * обрабатывают escape ('\n' → перевод строки), '#' внутри кавычек —
     * часть значения; одинарные кавычки берутся буквально; хвостовой
     * комментарий у значения без кавычек отбрасывается; пустые строки
     * и комментарии не становятся ключами; EMPTY= даёт пустую строку.
     */
    public function testParseQuotesExportAndComments(): void
    {
        $this->file = sys_get_temp_dir() . '/dbt_env_' . bin2hex(random_bytes(3)) . '.env';
        file_put_contents($this->file, implode("\n", [
            '# leading comment',
            'export DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            "DB_PASSWORD=\"p@ss\\nw0rd\"",   // двойные кавычки, escape обрабатывается
            "DB_USER='app'",                // одинарные кавычки
            'DB_NAME=mydb   # trailing comment',
            '',
            '   ',
            'EMPTY=',
        ]));
        $v = Env::parse($this->file);
        $this->assertSame('127.0.0.1', $v['DB_HOST']);   // префикс export снят
        $this->assertSame('3306', $v['DB_PORT']);
        $this->assertSame("p@ss\nw0rd", $v['DB_PASSWORD']); // \n разэкранирован, # сохранён
        $this->assertSame('app', $v['DB_USER']);
        $this->assertSame('mydb', $v['DB_NAME']);        // хвостовой комментарий отброшен
        $this->assertSame('', $v['EMPTY']);
        $this->assertArrayNotHasKey('#', $v);
    }

    /**
     * Отсутствующий .env — не ошибка: parse() возвращает пустой массив,
     * чтобы экстракторы могли мягко откатываться к другим источникам.
     */
    public function testParseMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], Env::parse(sys_get_temp_dir() . '/dbt_nope_' . bin2hex(random_bytes(3)) . '.env'));
    }
}
