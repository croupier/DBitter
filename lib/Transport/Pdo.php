<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Sql;

/**
 * "pdo" transport: working with MySQL without the mysql/mysqldump binaries —
 * SQL actions only (create-db, create-user, drop-db, doctor) over a PDO
 * connection from the local PHP. Dump export/import is unavailable: mysqldump
 * cannot be replaced through PDO; for that use the local/wsl/docker/ssh transports.
 */
final class Pdo extends Transport
{
    /**
     * Transport name for logs and diagnostics.
     *
     * @return string Always 'pdo'.
     */
    public function name(): string
    {
        return 'pdo';
    }

    /**
     * Launching binaries is not supported by this transport — it deliberately
     * throws an exception hinting which transports to pick for
     * export/import. SQL actions take a separate path through Sql/PDO
     * and never call this method.
     *
     * @param list<string> $argv Command arguments (ignored).
     * @param array<string,string> $env Environment variables (ignored).
     * @return array{cmd:string|list<string>,env:array<string,string>} Never returns.
     * @throws \RuntimeException Always: target=pdo supports SQL actions only.
     */
    public function launch(array $argv, array $env = []): array
    {
        throw new \RuntimeException(
            'target=pdo supports SQL actions only (create-db, create-user, drop-db, doctor). '
            . 'For export/import use local, wsl, docker or ssh.'
        );
    }

    /**
     * Diagnostics: checks that the pdo_mysql extension is loaded and that a
     * real connection to the server can be made. The connection is opened WITHOUT
     * selecting a database (null) — server availability and credentials are
     * verified, not the existence of the DB. The server version is read with a
     * SELECT VERSION() query.
     *
     * @return array{ok:bool,info:string} ok — connection established; info — the server version or the error message.
     */
    public function doctor(): array
    {
        if (!extension_loaded('pdo_mysql')) {
            return ['ok' => false, 'info' => 'pdo_mysql extension not loaded'];
        }
        $db = $this->cfg->db();
        try {
            $pdo = Sql::connect($db['host'], (int) $db['port'], $db['user'], $db['pass'], null, $db['charset'], $db['socket'] ?? null);
            $version = (string) Sql::scalar($pdo, 'SELECT VERSION()');
            return ['ok' => true, 'info' => 'PDO connection OK | ' . $version];
        } catch (\Throwable $e) {
            // Любая ошибка подключения/запроса — диагноз, а не падение doctor.
            return ['ok' => false, 'info' => $e->getMessage()];
        }
    }
}
