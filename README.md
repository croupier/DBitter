# DBitter

Universal MySQL migration tool for the command line.

**English** | [Русский](README.ru.md)

DBitter exports and imports MySQL dumps, creates databases and users, and diagnoses
connectivity — wherever your MySQL lives: on the host, inside WSL, in a Docker
container, on a remote SSH server, or over a plain PDO connection. It reads database
credentials from your project's own configuration (`wp-config.php`, Laravel `.env`,
custom const files), so there is nothing to duplicate.

It works as a Composer package (`vendor/bin/dbitter`) or as a standalone folder you
drop next to — or above — any project. The standalone mode needs no Composer at all:
the tool ships its own class autoloader.

## Features

- **7 actions**: `doctor`, `export`, `import`, `create-db`, `create-user`, `drop-db`, `show-command`
- **5 transports**: `local`, `wsl`, `docker`, `ssh`, `pdo` (PDO covers SQL actions only — no dumps)
- **Config extractors**: `wp` (WordPress), `laravel` (.env), `env`, `const` — with auto-detection
- **Dumps**: full / schema-only / data-only, gzip or zstd compression, splitting into MiB-sized parts, manifests, retention by count and age
- **Safe by default**: passwords never appear in process arguments (`MYSQL_PWD` / SQL script files are used instead); project configs are *parsed*, never `require`d; `--dry-run` prints masked commands
- **Interactive menu** when no action is given; every prompt can be pre-answered via CLI flags
- **Logging**: `--quiet`, `--verbose`, `--log=path`

## Requirements

- PHP >= 8.3 (CLI) with `ext-zlib`
- `mysql` / `mysqldump` clients reachable through the chosen transport (on the host, in WSL, in the container, or on the remote server)
- Optional: `zstd` binary for zstd compression; `ext-pdo_mysql` for the `pdo` transport

## Installation

### As a Composer package

```bash
composer require croupier/dbitter
vendor/bin/dbitter doctor
```

### Standalone

Clone or copy the DBitter folder next to your project (or anywhere above it) and run:

```bash
php start.php doctor
```

No `composer install` is required for runtime — Composer is only needed to run the
test suite. `php start.php` and `bin/dbitter` are equivalent entry points.

## Quick start

```bash
dbitter doctor                          # check environment & connectivity
dbitter export                          # dump the project database (gzip by default)
dbitter export --kind=schema --compress=none
dbitter import                          # import the latest dump
dbitter import --file=dump/backup.sql.gz --database=staging
dbitter create-db --name=newdb --collation=utf8mb4_unicode_ci
dbitter create-user --name=newdb --grant=ALL   # account from the project config
dbitter drop-db --name=olddb            # destructive, asks for confirmation
dbitter show-command export             # print the resolved commands, passwords masked
```

Run `dbitter --help` for the full option list, or `dbitter` with no action for the
interactive menu.

## Configuration

DBitter locates the **project root** by walking up from the current working
directory until it finds a marker: `dbitter.php`, `wp-config.php`, `artisan`,
`.env` or `composer.json`. The tool's own directory is skipped, and the walk
stops at a `.git` boundary. If nothing is found, it falls back to the parent of
the tool directory (the classic standalone layout).

Settings are merged from six layers, highest priority first:

1. **CLI flags** — `--host=`, `--target=`, `--kind=`, …
2. **`dbitter.local.php`** — project root, gitignored; passwords and machine-specific overrides
3. **`dbitter.php`** — project root, the committed project profile (no secrets!)
4. **`config.local.php`** — tool folder, gitignored (standalone mode)
5. **`config.php`** — tool folder profile (standalone mode; not shipped in the Composer package)
6. **Transport defaults**

Database credentials themselves come from the project config selected by `conf`
(`wp` | `laravel` | `env` | `const`). When `conf` is omitted, DBitter auto-detects the
type by markers in the project root (`wp-config.php`, `artisan`, `.env`).
Use `--config=path` (or `conf_path` in the profile) to point at the file explicitly.
A layer can reset an inherited value with `null` (e.g. `'conf_path' => null`).

Example `dbitter.php` (project root, committed):

```php
<?php
return [
    'conf' => 'wp',
    'target' => 'wsl',
    'dump' => [
        'compress' => 'gzip',
        'retention-keep' => 10,
    ],
];
```

Example `dbitter.local.php` (project root, gitignored):

```php
<?php
return [
    'conf_path' => 'D:/sites/myproject/wp-config.php',
    'admin' => ['user' => 'root', 'pass' => 'secret'],
    'transport' => [
        'wsl' => ['distro' => 'Ubuntu', 'user' => 'root'],
    ],
];
```

With `conf=const`, credentials are read from a PHP file returning an array
(`['db' => [...]]` or flat): `dbitter.local.php` → `dbitter.php` → the tool's
`config.local.php`, first file with a database name wins. This file is
executed (`require`), so only point it at your own config.

Dumps go to `<project>/dump` by default (`<tool>/dump` in the legacy fallback);
set `dump.dir` to override. Dump file names and manifests use the project
directory name.

### Transports

| Target   | How clients run                                            | Key settings                          |
|----------|------------------------------------------------------------|---------------------------------------|
| `local`  | `mysql`/`mysqldump` from the host PATH (or explicit paths) | `mysql`, `mysqldump`                  |
| `wsl`    | `wsl.exe -d <distro> -u <user> -- mysql …`                 | `distro`, `user`, binary paths        |
| `docker` | `docker exec` in a container or compose service            | `container` or `service` (+ `compose_file`) |
| `ssh`    | remote shell over `ssh` (key auth)                         | `host`, `port`, `user`, `key`, `options` |
| `pdo`    | PHP PDO connection, no external clients — SQL actions only (`doctor`, `create-db`, `create-user`, `drop-db`); export/import unavailable | `pdo_mysql` extension, host/port/user/pass from the project config |

## Security notes

- Passwords are passed via `MYSQL_PWD` or temporary SQL script files — never in
  `argv`, so they don't leak into process listings or shell history.
- `wp-config.php` and other project configs are parsed as text, never executed.
- `--dry-run` and `show-command` mask secrets in output.
- Keep real credentials in `dbitter.local.php` / `config.local.php` (gitignored),
  not in the committed profiles.

## Development & tests

```bash
composer install
composer test          # unit suite
composer test:coverage # unit suite with coverage (requires Xdebug)
DBT_IT=1 composer test-it   # integration suite against a live MySQL
```

Integration tests are skipped unless `DBT_IT=1` is set; they expect a reachable
MySQL server and admin credentials in `config.local.php`.

## License

[MIT](LICENSE) — release notes in [CHANGELOG.md](CHANGELOG.md).
