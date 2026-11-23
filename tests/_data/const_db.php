<?php

/**
 * Test DB connection fixture for the `const` extractor.
 * Used by unit tests (ExportArgsTest, TransportLaunchTest) and can also be
 * used by integration ones: Config is built with the flags
 * --conf=const --config=<this file> --target=<transport name>.
 * All values are fictitious — they do not touch a real database; the fixture
 * password it_secret_pw is needed to verify that the secret does NOT leak into argv.
 */

declare(strict_types=1);

// Экстрактор const просто возвращает этот массив как есть (секция 'db'),
// без чтения конфигов приложения — удобно для детерминированных тестов.
return [
    'db' => [
        'engine' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'it_user',
        'pass' => 'it_secret_pw',
        'name' => 'it_database',
        'charset' => 'utf8mb4',
        'prefix' => 'wp_',
    ],
];
