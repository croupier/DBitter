<?php

/**
 * DBitter TOOL profile (lowest layer) — committed to git, secrets must NOT
 * live here. This is the base settings layer: the project config extractor,
 * the transport to MySQL and the dump parameters. Any value is overridden in
 * config.local.php (gitignored, for passwords and local quirks), by the
 * project profile dbitter.php/dbitter.local.php in the project root (higher
 * layers) or by CLI flags. Precedence: CLI > dbitter.local.php > dbitter.php
 * > config.local.php > config.php > transport defaults. Profile merging is
 * recursive (array_replace_recursive in Config::loadProfile). This file is
 * excluded from the composer archive (export-ignore in .gitattributes):
 * package consumers configure the tool with their own dbitter.php in the project.
 */

declare(strict_types=1);

return [
    // Экстрактор параметров БД из конфига проекта: wp | laravel | env | const.
    // Если ключ убрать — Config::detectConf определит тип автоматически
    // по маркерам в родительском каталоге (wp-config.php, artisan, .env).
    'conf' => 'wp',
    // 'conf_path' => null,        // явный путь к wp-config.php / .env / const-файлу

    // Транспорт — как запускать клиенты mysql/mysqldump:
    // local | wsl | docker | ssh | pdo. Здесь MySQL живёт в WSL.
    'target' => 'wsl',

    // Настройки каждого транспорта; используется только секция активного target.
    'transport' => [
        'wsl' => [
            'distro' => 'Ubuntu',
            'user' => 'root',
            // 'mysql' => '/usr/bin/mysql',       // явные пути к бинарникам внутри WSL
            // 'mysqldump' => '/usr/bin/mysqldump',
        ],
        'local' => [
            // 'mysql' => 'mysql',                // пусто = искать в PATH хоста
            // 'mysqldump' => 'mysqldump',
        ],
        'ssh' => [
            'host' => '',
            'port' => 22,
            'user' => 'root',
            'key' => '',
            'options' => ['StrictHostKeyChecking=accept-new'],
        ],
        'docker' => [
            // либо 'container' => 'имя', либо 'service' => 'db' (+ опциональный compose_file)
            'container' => '',
        ],
    ],

    // Административный аккаунт MySQL для create-db / create-user / drop-db.
    // Реальный пароль кладите ТОЛЬКО в config.local.php, не сюда.
    'admin' => ['user' => 'root', 'pass' => ''],

    // Параметры выгрузки/импорта дампов; переопределяются CLI-флагами
    // (--kind, --compress, --split, --dir и т.д.) через Config::opt().
    'dump' => [
        'dir' => __DIR__ . '/dump',
        'kind' => 'full',
        'compress' => 'gzip',
        'split' => 0,                 // MiB на часть; 0 = единый файл без разбиения
        'routines' => true,
        'triggers' => true,
        'events' => true,
        'mysqldump_extra' => [],      // сырые флаги mysqldump для проекта, напр. '--column-statistics=0'
        'mysql_extra' => [],          // сырые флаги клиента mysql (НЕ mysqldump), напр. '--skip-column-names'
        'retention-keep' => 10,       // хранить N последних дампов; 0 = никогда не чистить
        'retention-days' => 0,        // дополнительно удалять дампы старше N дней; 0 = выключено
    ],
];
