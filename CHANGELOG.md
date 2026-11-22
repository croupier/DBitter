# Changelog

All notable changes to DBitter are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Composer package skeleton: `type: library`, PSR-4 autoloading, `bin/dbitter`
  entry point with dual autoloader lookup (project `vendor/` or standalone
  checkout), `ext-zlib` requirement, MIT license.
- Project discovery: the project root is located by walking up from the current
  working directory (markers: `dbitter.php`, `wp-config.php`, `artisan`, `.env`,
  `composer.json`; the tool directory is skipped; a `.git` directory stops the
  walk). Falls back to the tool's parent directory when nothing is found.
- Project-layer profiles `dbitter.php` / `dbitter.local.php` in the project
  root, merged above the tool-layer `config.php` / `config.local.php`.
- `Config::projectRoot()` / `Config::projectName()`; injectable cwd for tests.
- `const` extractor candidate chain: `dbitter.local.php` → `dbitter.php` →
  tool `config.local.php`; files without a database name are skipped.
- Bilingual documentation: `README.md` (English) and `README.ru.md` (Russian).
- Unit tests for discovery, layering and fallbacks (`ConfigDiscoveryTest`).

### Changed

- Default dump directory is `<project>/dump` when the project is discovered
  from cwd (`<tool>/dump` in the legacy fallback); explicit `dump.dir` wins.
- Dump file names and manifests use the discovered project name.
- Settings precedence is six layers:
  CLI > `dbitter.local.php` > `dbitter.php` > `config.local.php` > `config.php`
  > transport defaults. Inherited values can be reset with `null`.
- `composer archive` no longer ships the author's working profile, secrets,
  `vendor/` or test tooling (explicit `export-ignore`).

### Removed

- Global helper `dbt_project()` (replaced by `Config::projectName()`).

## 2026-11-22 — baseline

### Added

- Initial standalone tool imported from `prodom/.dbTools`: export/import dumps
  (gzip/zstd, split, retention), `create-db` / `create-user` / `drop-db`,
  `doctor`, `show-command`; transports `local` / `wsl` / `docker` / `ssh` / `pdo`;
  config extractors `wp` / `laravel` / `env` / `const`; interactive menu;
  unit and integration test suites.
