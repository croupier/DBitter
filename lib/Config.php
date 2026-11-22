<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Единая точка конфигурации DBitter. Приоритет источников (от старшего
 * к младшему): аргументы CLI > config.local.php > config.php > встроенные
 * дефолты. Параметры БД извлекаются экстрактором из conf/<имя>.php
 * (wp/laravel/env/const; wp-config.php ПАРСИТСЯ, а не require-ится),
 * затем нормализуются до полной схемы и перекрываются CLI-ключами.
 * Также отдаёт target/транспорт, админ-доступ, опции дампов и список
 * секретов для маскировки (Command::mask). Используется всеми действиями.
 */
final class Config
{
    /** @var array<string,mixed> Слитый профиль: config.php + config.local.php поверх. */
    private array $profile;
    /** @var array<string,mixed> Разобранные аргументы/опции CLI (высший приоритет). */
    private array $cli;
    /** @var array<string,mixed> Нормализованные параметры БД (host/port/user/pass/name/...). */
    private array $db;
    /** @var string Корень инструмента (DBitter); родитель — каталог проекта. */
    private string $root;

    /**
     * Загружает профиль и сразу разрешает параметры БД, чтобы дальше
     * конфиг был неизменяемым снимком (ошибки конфигурации видны при старте).
     *
     * @param string $root Путь к корню инструмента (каталог DBitter).
     * @param array<string,mixed> $cli Опции командной строки.
     * @throws \RuntimeException Если выбранный экстрактор conf не найден.
     */
    public function __construct(string $root, array $cli = [])
    {
        $this->root = $root;
        $this->cli = $cli;
        $this->profile = self::loadProfile($root);
        $this->db = $this->resolveDb();
    }

    /**
     * Загружает профиль конфигурации: базовый config.php, затем
     * config.local.php рекурсивно поверх (array_replace_recursive) —
     * локальные переопределения (пароли, target=wsl и т.п.) не требуют
     * правок основного файла и не попадают в git.
     *
     * @param string $root Корень инструмента.
     * @return array<string,mixed> Слитый профиль (пустой, если файлов нет).
     */
    private static function loadProfile(string $root): array
    {
        $profile = [];
        $main = $root . '/config.php';
        if (is_file($main)) {
            $profile = require $main;
        }
        $local = $root . '/config.local.php';
        if (is_file($local)) {
            $profile = array_replace_recursive($profile, require $local);
        }
        return $profile;
    }

    /**
     * Разрешает параметры БД: выбирает экстрактор conf (CLI 'conf' >
     * профиль 'conf' > автоопределение detectConf), вызывает его с опциями
     * (путь к конфигу проекта, флаг bootstrap, корень проекта), дополняет
     * результат дефолтами до полной схемы и накладывает CLI-переопределения.
     *
     * @return array<string,mixed> Полная нормализованная схема параметров БД.
     * @throws \RuntimeException Если файл conf/<имя>.php не существует.
     */
    private function resolveDb(): array
    {
        // Приоритет выбора экстрактора: CLI > профиль > автодетект по проекту.
        $conf = $this->cli['conf'] ?? $this->profile['conf'] ?? self::detectConf($this->root);
        $file = $this->root . '/conf/' . $conf . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Unknown conf extractor: {$conf}");
        }
        $extractor = require $file;
        $opts = [
            'path' => $this->cli['config'] ?? $this->profile['conf_path'] ?? null,
            'bootstrap' => (bool) ($this->cli['bootstrap'] ?? false),
            'root' => dirname($this->root),
        ];
        $db = $extractor($opts);

        // Нормализуем ПОЛНУЮ схему: частичный/кастомный экстрактор не должен
        // приводить к undefined-index warnings ниже по коду (Doctor, манифест,
        // clientBase). Оператор += добавляет только отсутствующие ключи.
        $db += [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'pass' => '',
            'name' => '',
            'charset' => 'utf8mb4',
            'prefix' => '',
            'socket' => null,
        ];

        // CLI перекрывает всё, но пустая строка не считается переопределением
        // (иначе --pass= без значения затёр бы реальный пароль из конфига).
        foreach (['host', 'port', 'user', 'pass', 'name', 'charset', 'prefix', 'socket'] as $key) {
            if (isset($this->cli[$key]) && $this->cli[$key] !== '') {
                $db[$key] = $this->cli[$key];
            }
        }
        // Порт приводим к int; 0/'' (например, из .env) откатывается к 3306.
        $db['port'] = (int) ($db['port'] ?: 3306);
        $db['engine'] = $db['engine'] ?? 'mysql';
        $db['charset'] = $db['charset'] ?? 'utf8mb4';
        // user_host — хост в CREATE USER 'u'@'host'; по умолчанию localhost.
        $db['user_host'] = $db['user_host'] ?? 'localhost';

        return $db;
    }

    /**
     * Автоопределение экстрактора по файлам-маркерам проекта (родитель
     * каталога DBitter): wp-config.php => WordPress; artisan + .env =>
     * Laravel; только .env => generic env; иначе const (константы/дефолты).
     * Порядок важен: wp-config проверяется первым, .env — последним из файлов.
     *
     * @param string $root Корень инструмента.
     * @return string Имя экстрактора: 'wp', 'laravel', 'env' или 'const'.
     */
    private static function detectConf(string $root): string
    {
        $project = dirname($root);
        if (is_file($project . '/wp-config.php')) {
            return 'wp';
        }
        if (is_file($project . '/artisan') && is_file($project . '/.env')) {
            return 'laravel';
        }
        if (is_file($project . '/.env')) {
            return 'env';
        }
        return 'const';
    }

    /**
     * Нормализованные параметры подключения к БД проекта.
     *
     * @return array<string,mixed> Схема из resolveDb (host/port/user/pass/name/...).
     */
    public function db(): array
    {
        return $this->db;
    }

    /**
     * Слитый профиль config.php + config.local.php (без CLI).
     *
     * @return array<string,mixed> Профиль конфигурации.
     */
    public function profile(): array
    {
        return $this->profile;
    }

    /**
     * Опции командной строки, как их разобрал start.php.
     *
     * @return array<string,mixed> CLI-опции.
     */
    public function cli(): array
    {
        return $this->cli;
    }

    /**
     * Корень инструмента (каталог DBitter).
     *
     * @return string Абсолютный путь.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Транспорт исполнения команд MySQL: CLI 'target' > профиль 'target' >
     * 'local'. Возможные значения: local/wsl/docker/ssh/pdo (в этом проекте
     * обычно wsl — MySQL внутри WSL Ubuntu).
     *
     * @return string Имя транспорта.
     */
    public function target(): string
    {
        return $this->cli['target'] ?? $this->profile['target'] ?? 'local';
    }

    /**
     * Настройки выбранного транспорта из профиля (profile['transport'][target]),
     * например wsl-дистрибутив, docker-контейнер, ssh-хост.
     *
     * @return array<string,mixed> Настройки транспорта или пустой массив.
     */
    public function transportConfig(): array
    {
        return $this->profile['transport'][$this->target()] ?? [];
    }

    /**
     * Административный доступ к MySQL (для create-db/create-user/drop-db):
     * профиль 'admin' или дефолт root без пароля.
     *
     * @return array<string,mixed> Ключи 'user' и 'pass'.
     */
    public function admin(): array
    {
        return $this->profile['admin'] ?? ['user' => 'root', 'pass' => ''];
    }

    /**
     * Универсальный доступ к опции с учётом приоритета: CLI >
     * profile['dump'][key] (секция настроек дампов) > profile[key] >
     * переданный дефолт. Так, например, читаются compress/part/keep.
     *
     * @param string $key Имя опции.
     * @param mixed $default Значение, если опция нигде не задана.
     * @return mixed Найденное значение или дефолт.
     */
    public function opt(string $key, mixed $default = null): mixed
    {
        return $this->cli[$key] ?? $this->profile['dump'][$key] ?? $this->profile[$key] ?? $default;
    }

    /**
     * Каталог дампов: опция 'dir' (CLI/профиль) или <root>/dump по
     * умолчанию; завершающие слэши срезаются для единообразия путей.
     *
     * @return string Путь каталога дампов без хвостового слэша.
     */
    public function dumpDir(): string
    {
        $dir = $this->opt('dir', $this->root . '/dump');
        return rtrim((string) $dir, '/\\');
    }

    /**
     * Метка окружения для имён дампов/манифестов: опция 'env' или,
     * если не задана, имя транспорта (target).
     *
     * @return string Имя окружения (например, 'wsl').
     */
    public function env(): string
    {
        return (string) $this->opt('env', $this->target());
    }

    /**
     * Список непустых секретов (пароль БД проекта и админ-пароль) для
     * маскировки в отображаемых командах и логах (Command::mask).
     *
     * @return list<string> Секреты; пустые значения отфильтрованы.
     */
    public function secrets(): array
    {
        return array_values(array_filter([
            $this->db['pass'] ?? '',
            $this->admin()['pass'] ?? '',
        ], static fn(string $s): bool => $s !== ''));
    }
}
