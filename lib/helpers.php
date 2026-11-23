<?php

declare(strict_types=1);

/**
 * DBitter global helper functions (no namespace so they can be called
 * briefly from anywhere). Loaded from lib/bootstrap.php. They expose access
 * to the tool root (the DbTools\ROOT constant) and to a unique timestamp
 * label generator used for dump and manifest names (see Dump::baseName).
 * The project name is now returned by Config::projectName() — derived from
 * the located project root, not from the tool's own location.
 */

/**
 * Returns the absolute path to the tool root (the DBitter directory).
 * Used to build paths to config.php, conf/, dump/ and the like.
 *
 * @return string Path without a trailing slash.
 */
function dbt_root(): string
{
    return \DbTools\ROOT;
}

/**
 * Timestamp label for dump/manifest file names: "Ymd-His-xxx".
 *
 * @return string For example, "20261110-153045-a1b".
 */
function dbt_now(): string
{
    // Одной точности до секунды недостаточно: два экспорта в пределах одной
    // секунды перезаписали бы файлы друг друга. Короткий случайный суффикс
    // (3 hex-символа из 2 случайных байт) гарантирует уникальность имён.
    return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 3);
}
