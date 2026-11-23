<?php

/**
 * PHPUnit bootstrap (loaded from phpunit.xml.dist).
 * Prepares the environment for unit and integration tests: autoloading of
 * PHPUnit and DbTools\Tests\*, plus the full bootstrap of the tool itself,
 * so tests see the same classes and helpers as the production start.php.
 */

declare(strict_types=1);

// Composer-автозагрузчик: PHPUnit и классы DbTools\Tests\* (autoload-dev).
require dirname(__DIR__) . '/vendor/autoload.php';

// Бутстрап самого инструмента: определяет константу DbTools\ROOT,
// регистрирует автозагрузчик DbTools\* (lib/, conf/, actions/)
// и подключает глобальные вспомогательные функции (lib/helpers.php).
require dirname(__DIR__) . '/lib/bootstrap.php';
