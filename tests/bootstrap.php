<?php

/**
 * Бутстрап PHPUnit (подключается из phpunit.xml.dist).
 * Готовит окружение для юнит- и интеграционных тестов: автозагрузку
 * PHPUnit и DbTools\Tests\*, а также полный бутстрап самого инструмента,
 * чтобы тесты видели те же классы и хелперы, что и боевой start.php.
 */

declare(strict_types=1);

// Composer-автозагрузчик: PHPUnit и классы DbTools\Tests\* (autoload-dev).
require dirname(__DIR__) . '/vendor/autoload.php';

// Бутстрап самого инструмента: определяет константу DbTools\ROOT,
// регистрирует автозагрузчик DbTools\* (lib/, conf/, actions/)
// и подключает глобальные вспомогательные функции (lib/helpers.php).
require dirname(__DIR__) . '/lib/bootstrap.php';
