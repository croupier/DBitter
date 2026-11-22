<?php

/**
 * Базовый класс интеграционных тестов: гоняют настоящий CLI start.php
 * сабпроцессом против живого MySQL. Транспорт и подключение берутся из
 * собственного конфига проекта (config.php + config.local.php).
 * По умолчанию выключены (DBT_IT != 1 → markTestSkipped), чтобы юнит-прогон
 * `composer test` никогда не требовал базу данных.
 */

declare(strict_types=1);

namespace DbTools\Tests\Integration;

use DbTools\Lib\Command;
use DbTools\Lib\Result;
use PHPUnit\Framework\TestCase;

/**
 * Общая инфраструктура интеграционных тестов.
 *
 * Ключевые особенности:
 * - gate DBT_IT=1 в setUp(): без живой БД тесты пропускаются, а не падают;
 * - каждое действие выполняется ОТДЕЛЬНЫМ PHP-процессом (runTool), поэтому
 *   код start.php/lib внутри сабпроцесса НЕ попадает в coverage PHPUnit —
 *   покрытие собирается только в процессе самого тест-раннера. Это плата
 *   за реалистичность: проверяется настоящий CLI целиком, включая exit-коды.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Корень инструмента (DBitter) — от него строится путь к start.php. */
    protected string $toolRoot;

    /**
     * Проверяет gate DBT_IT=1 и вычисляет корень инструмента.
     * Без переменной окружения тест помечается пропущенным — юнит-прогон
     * остаётся полностью автономным.
     */
    protected function setUp(): void
    {
        if (getenv('DBT_IT') !== '1') {
            $this->markTestSkipped('Set DBT_IT=1 to run integration tests against a live MySQL server.');
        }
        $this->toolRoot = dirname(__DIR__, 2);
    }

    /**
     * Запускает одно действие DBitter сабпроцессом и возвращает его Result
     * (exit-код, stdout/stderr). PHP_BINARY — тот же интерпретатор, что
     * запустил PHPUnit, поэтому версия PHP в тесте и в инструменте совпадает.
     *
     * @param list<string> $args напр. ['export', '--kind=schema', '--dir=/tmp']
     */
    final protected function runTool(array $args): Result
    {
        $cmd = array_merge([PHP_BINARY, $this->toolRoot . '/start.php'], $args);
        return Command::capture($cmd);
    }
}
