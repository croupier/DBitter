<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Единая точка конфигурации DBitter. Приоритет источников (от старшего
 * к младшему): аргументы CLI > dbitter.local.php > dbitter.php (профиль
 * проекта) > config.local.php > config.php (профиль инструмента) >
 * встроенные дефолты. Корень проекта ищется вверх от cwd по маркерам
 * (dbitter.php, wp-config.php, artisan, .env, composer.json); если ничего
 * не найдено — legacy-фолбэк: родитель каталога инструмента. Параметры БД
 * извлекаются экстрактором из conf/<имя>.php (wp/laravel/env/const;
 * wp-config.php ПАРСИТСЯ, а не require-ится), затем нормализуются до полной
 * схемы и перекрываются CLI-ключами. Также отдаёт target/транспорт,
 * админ-доступ, опции дампов и список секретов для маскировки
 * (Command::mask). Используется всеми действиями.
 */
final class Config
{
    /** @var array<string,mixed> Слитый профиль: слои инструмента + слои проекта поверх. */
    private array $profile;
    /** @var array<string,mixed> Разобранные аргументы/опции CLI (высший приоритет). */
    private array $cli;
    /** @var array<string,mixed> Нормализованные параметры БД (host/port/user/pass/name/...). */
    private array $db;
    /** @var string Корень инструмента (DBitter): код, conf/, профили config*.php. */
    private string $root;
    /** @var string Корень проекта: найден вверх от cwd либо dirname(root) в legacy-фолбэке. */
    private string $projectRoot;
    /** @var bool Проект найден discovery-проходом от cwd (а не legacy-фолбэком). */
    private bool $projectFound;

    /**
     * Загружает профиль и сразу разрешает параметры БД, чтобы дальше
     * конфиг был неизменяемым снимком (ошибки конфигурации видны при старте).
     *
     * @param string $root Путь к корню инструмента (каталог DBitter).
     * @param array<string,mixed> $cli Опции командной строки.
     * @param string|null $cwd Стартовый каталог discovery проекта; null = getcwd().
     *                         Инъекция нужна тестам и сабпроцессам с другим cwd.
     * @throws \RuntimeException Если выбранный экстрактор conf не найден.
     */
    public function __construct(string $root, array $cli = [], ?string $cwd = null)
    {
        $this->root = $root;
        $this->cli = $cli;
        $discovered = self::discoverProject($cwd ?? (string) getcwd(), $root);
        // Фолбэк повторяет историческое поведение standalone-режима: инструмент
        // лежит внутри проекта (или рядом), проект = родитель каталога инструмента.
        $this->projectRoot = $discovered ?? dirname($root);
        $this->projectFound = $discovered !== null;
        $this->profile = self::loadProfile($root, $this->projectRoot);
        $this->db = $this->resolveDb();
    }

    /**
     * Ищет корень проекта вверх от cwd до корня файловой системы.
     *
     * Маркеры проекта в каталоге: dbitter.php (явный профиль DBitter),
     * wp-config.php, artisan, .env, composer.json. Каталог самого инструмента
     * пропускается: DBitter не должен считать «проектом» сам себя (в пакете
     * есть composer.json, в репозитории — .git). Граница обхода — каталог
     * с .git: выше VCS-корня проект не ищем, чтобы не подхватить чужой
     * composer.json/.env из родительских каталогов.
     *
     * @param string $cwd Стартовый каталог (обычно getcwd()).
     * @param string $toolRoot Корень инструмента — пропускается при обходе.
     * @return string|null Найденный корень проекта или null (нужен фолбэк).
     */
    private static function discoverProject(string $cwd, string $toolRoot): ?string
    {
        $tool = self::normalizePath($toolRoot);
        $dir = $cwd;
        while (true) {
            // Сравниваем каталоги через realpath (симлинки/junction, регистр,
            // слэши), но возвращаем исходный путь — как его задал вызывающий.
            if (self::normalizePath($dir) !== $tool) {
                foreach (['dbitter.php', 'wp-config.php', 'artisan', '.env', 'composer.json'] as $marker) {
                    if (is_file($dir . '/' . $marker)) {
                        return $dir;
                    }
                }
                // .git бывает и каталогом, и файлом (worktree/submodule) — оба варианта.
                if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                    return null;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null; // корень ФС достигнут, маркеров нет
            }
            $dir = $parent;
        }
    }

    /**
     * Нормализует путь для сравнения каталогов: realpath разрешает симлинки
     * и junction (важно для vendor-режима на Windows) и унифицирует слэши.
     *
     * @param string $path Абсолютный путь.
     * @return string Путь с прямыми слэшами, без хвостового слэша.
     */
    private static function normalizePath(string $path): string
    {
        $real = realpath($path);
        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }

    /**
     * Загружает профиль конфигурации четырьмя слоями (младший → старший):
     * config.php и config.local.php из корня инструмента (standalone-профиль),
     * затем dbitter.php и dbitter.local.php из корня проекта. Каждый слой
     * накладывается рекурсивно (array_replace_recursive) — локальные
     * переопределения (пароли, target=wsl и т.п.) не требуют правок основных
     * файлов и не попадают в git. В composer-архив config.php не входит
     * (export-ignore), поэтому у потребителя работают только его dbitter*.php.
     *
     * @param string $root Корень инструмента.
     * @param string $projectRoot Корень проекта.
     * @return array<string,mixed> Слитый профиль (пустой, если файлов нет).
     */
    private static function loadProfile(string $root, string $projectRoot): array
    {
        $profile = [];
        foreach ([
            $root . '/config.php',
            $root . '/config.local.php',
            $projectRoot . '/dbitter.php',
            $projectRoot . '/dbitter.local.php',
        ] as $layer) {
            if (is_file($layer)) {
                $profile = array_replace_recursive($profile, require $layer);
            }
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
        $conf = $this->cli['conf'] ?? $this->profile['conf'] ?? self::detectConf($this->projectRoot);
        $file = $this->root . '/conf/' . $conf . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Unknown conf extractor: {$conf}");
        }
        $extractor = require $file;
        $opts = [
            'path' => $this->cli['config'] ?? $this->profile['conf_path'] ?? null,
            'bootstrap' => (bool) ($this->cli['bootstrap'] ?? false),
            'root' => $this->projectRoot,
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
     * Автоопределение экстрактора по файлам-маркерам в корне проекта:
     * wp-config.php => WordPress; artisan + .env => Laravel; только .env =>
     * generic env; иначе const (константы/дефолты). Порядок важен: wp-config
     * проверяется первым, .env — последним из файлов.
     *
     * @param string $project Корень проекта (найденный discovery или фолбэк).
     * @return string Имя экстрактора: 'wp', 'laravel', 'env' или 'const'.
     */
    private static function detectConf(string $project): string
    {
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
     * Корень проекта: найденный вверх от cwd, либо dirname(root) в
     * legacy-фолбэке. От него строятся пути экстракторов и дефолтный dump/.
     *
     * @return string Абсолютный путь.
     */
    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Имя проекта для имён дампов и манифестов — basename корня проекта.
     * Заменяет прежний dbt_project(), который всегда смотрел на родителя
     * каталога инструмента и врал в vendor-режиме.
     *
     * @return string Имя каталога проекта.
     */
    public function projectName(): string
    {
        return basename($this->projectRoot);
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
     * Каталог дампов: опция 'dir' (CLI/профиль) или дефолт — <project>/dump,
     * когда проект найден от cwd, иначе <tool>/dump (legacy-поведение).
     * Завершающие слэши срезаются для единообразия путей.
     *
     * @return string Путь каталога дампов без хвостового слэша.
     */
    public function dumpDir(): string
    {
        $base = $this->projectFound ? $this->projectRoot : $this->root;
        $dir = $this->opt('dir', $base . '/dump');
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
