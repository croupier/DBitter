<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Single configuration entry point for DBitter. Source priority (from
 * highest to lowest): CLI arguments > dbitter.local.php > dbitter.php (project
 * profile) > config.local.php > config.php (tool profile) >
 * built-in defaults. The project root is searched upward from cwd using
 * markers (dbitter.php, wp-config.php, artisan, .env, composer.json); if nothing
 * is found — legacy fallback: the parent of the tool directory. Database credentials
 * are pulled by the config extractor from conf/<name>.php (wp/laravel/env/const;
 * wp-config.php is PARSED, not require-d), then normalized to the full
 * schema and overridden by CLI keys. Also serves target/transport,
 * admin access, dump options and the list of secrets for masking
 * (Command::mask). Used by all actions.
 */
final class Config
{
    /** @var array<string,mixed> Merged profile: tool layers with the project layers on top. */
    private array $profile;
    /** @var array<string,mixed> Parsed CLI arguments/options (highest priority). */
    private array $cli;
    /** @var array<string,mixed> Normalized database credentials (host/port/user/pass/name/...). */
    private array $db;
    /** @var string Tool root (DBitter): code, conf/, config*.php profiles. */
    private string $root;
    /** @var string Project root: found upward from cwd, or dirname(root) in the legacy fallback. */
    private string $projectRoot;
    /** @var bool Project was found by the upward walk from cwd (not by the legacy fallback). */
    private bool $projectFound;

    /**
     * Loads the profile and resolves the database credentials right away, so that
     * the config is an immutable snapshot afterwards (configuration errors are visible at startup).
     *
     * @param string $root Path to the tool root (the DBitter directory).
     * @param array<string,mixed> $cli Command-line options.
     * @param string|null $cwd Starting directory for project discovery; null = getcwd().
     *                        Injection is needed for tests and subprocesses with a different cwd.
     * @throws \RuntimeException If the selected conf config extractor is not found.
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
     * Searches for the project root upward from cwd to the filesystem root.
     *
     * Project markers in a directory: dbitter.php (an explicit DBitter profile),
     * wp-config.php, artisan, .env, composer.json. The tool's own directory
     * is skipped: DBitter must not treat itself as the "project" (the package
     * ships a composer.json, the repository a .git). The boundary of the upward walk is the
     * directory containing .git: above the VCS root the project is not searched, so that we do not pick up someone else's
     * composer.json/.env from parent directories.
     *
     * @param string $cwd Starting directory (usually getcwd()).
     * @param string $toolRoot Tool root — skipped during the walk.
     * @return string|null The project root that was found, or null (fallback needed).
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
     * Normalizes a path for directory comparison: realpath resolves symlinks
     * and junctions (important for vendor mode on Windows) and unifies slashes.
     *
     * @param string $path Absolute path.
     * @return string Path with forward slashes and no trailing slash.
     */
    private static function normalizePath(string $path): string
    {
        $real = realpath($path);
        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }

    /**
     * Loads the configuration profile in four layers (lowest → highest):
     * config.php and config.local.php from the tool root (standalone profile),
     * then dbitter.php and dbitter.local.php from the project root. Each layer
     * is applied recursively (array_replace_recursive) — local
     * overrides (passwords, target=wsl and the like) need no edits to the main
     * files and stay out of git. The composer archive does not include config.php
     * (export-ignore), so a consumer only runs its own dbitter*.php.
     *
     * @param string $root Tool root.
     * @param string $projectRoot Project root.
     * @return array<string,mixed> Merged profile (empty when no files exist).
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
     * Resolves the database credentials: picks the conf config extractor (CLI 'conf' >
     * profile 'conf' > auto-detection via detectConf), calls it with the options
     * (path to the project config, bootstrap flag, project root), pads the
     * result with defaults up to the full schema and applies CLI overrides.
     *
     * @return array<string,mixed> Complete normalized schema of database credentials.
     * @throws \RuntimeException If the file conf/<name>.php does not exist.
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
     * Auto-detection of the config extractor by marker files in the project root:
     * wp-config.php => WordPress; artisan + .env => Laravel; only .env =>
     * generic env; otherwise const (constants/defaults). The order matters: wp-config
     * is checked first, .env is the last of the files checked.
     *
     * @param string $project Project root (found by the upward walk or the fallback).
     * @return string Config extractor name: 'wp', 'laravel', 'env' or 'const'.
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
     * Normalized database connection credentials of the project.
     *
     * @return array<string,mixed> Schema from resolveDb (host/port/user/pass/name/...).
     */
    public function db(): array
    {
        return $this->db;
    }

    /**
     * Merged profile config.php + config.local.php (without CLI).
     *
     * @return array<string,mixed> Configuration profile.
     */
    public function profile(): array
    {
        return $this->profile;
    }

    /**
     * Command-line options, as parsed by start.php.
     *
     * @return array<string,mixed> CLI options.
     */
    public function cli(): array
    {
        return $this->cli;
    }

    /**
     * Tool root (the DBitter directory).
     *
     * @return string Absolute path.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Project root: found upward from cwd, or dirname(root) in the
     * legacy fallback. Config extractor paths and the default dump/ are built from it.
     *
     * @return string Absolute path.
     */
    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Project name used in dump and manifest names — the basename of the project root.
     * Replaces the former dbt_project(), which always looked at the parent of the tool
     * directory and lied in vendor mode.
     *
     * @return string Project directory name.
     */
    public function projectName(): string
    {
        return basename($this->projectRoot);
    }

    /**
     * Transport for executing MySQL commands: CLI 'target' > profile 'target' >
     * 'local'. Possible values: local/wsl/docker/ssh/pdo (in this project
     * it is usually wsl — MySQL inside WSL Ubuntu).
     *
     * @return string Transport name.
     */
    public function target(): string
    {
        return $this->cli['target'] ?? $this->profile['target'] ?? 'local';
    }

    /**
     * Settings of the selected transport from the profile (profile['transport'][target]),
     * for example the wsl distribution, docker container, ssh host.
     *
     * @return array<string,mixed> Transport settings or an empty array.
     */
    public function transportConfig(): array
    {
        return $this->profile['transport'][$this->target()] ?? [];
    }

    /**
     * Administrative access to MySQL (for create-db/create-user/drop-db):
     * profile 'admin' or the default root with an empty password.
     *
     * @return array<string,mixed> The 'user' and 'pass' keys.
     */
    public function admin(): array
    {
        return $this->profile['admin'] ?? ['user' => 'root', 'pass' => ''];
    }

    /**
     * Generic access to an option honouring priority: CLI >
     * profile['dump'][key] (the dump settings section) > profile[key] >
     * the default passed in. This is how compress/part/keep are read, for instance.
     *
     * @param string $key Option name.
     * @param mixed $default Value used when the option is set nowhere.
     * @return mixed The value found, or the default.
     */
    public function opt(string $key, mixed $default = null): mixed
    {
        return $this->cli[$key] ?? $this->profile['dump'][$key] ?? $this->profile[$key] ?? $default;
    }

    /**
     * Dump directory: the 'dir' option (CLI/profile) or the default — <project>/dump,
     * when the project was found from cwd, otherwise <tool>/dump (legacy behaviour).
     * Trailing slashes are stripped for path consistency.
     *
     * @return string Dump directory path without a trailing slash.
     */
    public function dumpDir(): string
    {
        $base = $this->projectFound ? $this->projectRoot : $this->root;
        $dir = $this->opt('dir', $base . '/dump');
        return rtrim((string) $dir, '/\\');
    }

    /**
     * Environment label used in dump/manifest names: the 'env' option or,
     * when unset, the transport name (target).
     *
     * @return string Environment name (for example, 'wsl').
     */
    public function env(): string
    {
        return (string) $this->opt('env', $this->target());
    }

    /**
     * List of non-empty secrets (the project database password and the admin password) for
     * masking in displayed commands and logs (Command::mask).
     *
     * @return list<string> Secrets; empty values are filtered out.
     */
    public function secrets(): array
    {
        return array_values(array_filter([
            $this->db['pass'] ?? '',
            $this->admin()['pass'] ?? '',
        ], static fn(string $s): bool => $s !== ''));
    }
}
