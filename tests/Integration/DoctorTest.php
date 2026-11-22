<?php

/**
 * Интеграционный smoke-тест действия `doctor` против живого MySQL.
 * Проверяет, что сквозная цепочка работает end-to-end: разбор CLI →
 * Config → транспорт (local/wsl/...) → клиент mysql → сервер.
 * Ловит регрессии запуска транспорта и подключения, которые юнит-тесты
 * (без запуска команд) принципиально не видят. Требует DBT_IT=1.
 */

declare(strict_types=1);

namespace DbTools\Tests\Integration;

/**
 * Проверка диагностики `doctor`: все проверки окружения должны проходить
 * на корректно настроенной машине (клиенты доступны, сервер отвечает).
 */
final class DoctorTest extends IntegrationTestCase
{
    /**
     * doctor завершается с кодом 0 и печатает итог «All checks passed».
     * Регрессия: сломанный транспорт/подключение давали бы FAIL-строки
     * и ненулевой exit-код — тест сразу это подсветит (с выводом doctor).
     */
    public function testDoctorReportsAllChecksPassing(): void
    {
        $res = $this->runTool(['doctor']);
        $this->assertTrue($res->ok(), 'doctor failed: ' . $res->output());
        $this->assertStringContainsString('All checks passed', $res->output());
    }
}
