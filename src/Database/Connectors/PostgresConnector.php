<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\PostgresConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use Override;

/**
 * Postgres connector — builds a PDO Postgres connection from config.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\SqlConnection
 */
final class PostgresConnector extends SqlConnector
{
    /**
     * Create a Postgres connection from the given config.
     *
     * @param array<string,mixed> $config The connection config (host, port,
     *        database, username, password, …).
     * @return PostgresConnection A ready-to-use Postgres connection.
     * @throws \InvalidArgumentException If $host or $database is missing.
     */
    #[Override]
    public function connect(array $config): SqlConnection
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? null;

        if (!is_string($host) || !is_int($port) || !is_string($database)) {
            throw new \InvalidArgumentException(
                'Postgres requires a string host, an integer port and a string database.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database,
        );

        $options = $config['options'] ?? [];
        $pdo = $this->createPdo($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);

        // Postgres only supports native prepared statements (no emulation),
        // so the inherited EMULATE_PREPARES => false default is moot but
        // harmless. Native types with STRINGIFY_FETCHES => false are the
        // Postgres codec's contract (microsecond datetimes). No extra forced
        // attributes — Postgres' needs are met by the base defaults.
        return new PostgresConnection($pdo);
    }

    /**
     * Validate the shape of a Postgres connection config.
     *
     * Postgres needs `host` and `database` (the only fields it consumes
     * beyond the shared `driver`). The shared {@see SqlConnector::validConfig()}
     * already checked `driver`; this checks the Postgres-specific fields.
     *
     * @param array<string,mixed> $config The connection config to validate.
     * @throws \InvalidArgumentException When a required field is missing or
     *         malformed.
     */
    #[Override]
    public function validConfig(array $config): void
    {
        parent::validConfig($config);

        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;

        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException(
                'Postgres requires a non-empty string "host"; got '
                . ($host === null ? 'nothing' : get_debug_type($host))
                . '.'
            );
        }

        if (!is_string($database) || $database === '') {
            throw new \InvalidArgumentException(
                'Postgres requires a non-empty string "database"; got '
                . ($database === null ? 'nothing' : get_debug_type($database))
                . '.'
            );
        }
    }
}