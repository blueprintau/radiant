<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;

/**
 * Creates a connection from a config array.
 *
 * Each driver (mysql, sqlite, pgsql, csv, …) has its own connector
 * that turns the config into a ready-to-use connection. You normally don't
 * use these directly — a {@see \BlueprintAU\Radiant\Database\DatabaseManager}
 * picks the right one for you based on the `driver` key.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\ConnectionInterface
 */
interface ConnectorInterface
{
    /**
     * Create a connection from the given config.
     *
     * @param array<string,mixed> $config The connection config (driver, host,
     *        port, database, username, password, …).
     * @return ConnectionInterface A ready-to-use connection.
     */
    public function connect(array $config): ConnectionInterface;

    /**
     * Validate the shape of a connection config before it reaches
     * {@see connect()}.
     *
     * Every connector validates the fields it consumes — the shared `driver`
     * key plus its own driver-specific fields (e.g. SQLite's `database`
     * path). A malformed config is a configuration error, not a runtime
     * condition: it fails fast here with a message that names the problem,
     * rather than surfacing later as a confusing driver error.
     *
     * @param array<string,mixed> $config The connection config to validate.
     * @throws \InvalidArgumentException When the config is not shaped like
     *         this connector expects.
     */
    public function validConfig(array $config): void;
}
