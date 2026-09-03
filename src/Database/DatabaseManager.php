<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connectors\ConnectorInterface;

/**
 * Factory + registry for database connections.
 *
 * Holds the injected connections map, builds connections on demand, and
 * caches them by name. Each driver maps to a {@see ConnectorInterface} via
 * the extensible connector registry — not a hardcoded match — so new
 * backends can be added without touching this class.
 *
 * The connections map is validated at construction time: each entry must be
 * an array declaring a string `driver` that is registered in the connector
 * registry. A malformed map is a configuration error and fails fast here,
 * rather than surfacing later as a confusing connector lookup.
 */
final class DatabaseManager
{
    /**
     * Resolved connections, cached by name.
     *
     * @var array<string, \BlueprintAU\Radiant\Database\Connections\ConnectionInterface>
     */
    protected array $resolved = [];

    /**
     * The connector registry — driver name to connector class.
     *
     * Every driver must be explicitly registered here; an unregistered
     * driver is a configuration error, not a runtime fallback.
     *
     * @var array<string, class-string<\BlueprintAU\Radiant\Database\Connectors\ConnectorInterface>>
     */
    protected array $connectors = [
        'mysql' => \BlueprintAU\Radiant\Database\Connectors\MySqlConnector::class,
        'sqlite' => \BlueprintAU\Radiant\Database\Connectors\SqliteConnector::class,
        'pgsql' => \BlueprintAU\Radiant\Database\Connectors\PostgresConnector::class,
        'csv' => \BlueprintAU\Radiant\Database\Connectors\CsvConnector::class,
    ];

    /**
     * The name of the active connection.
     *
     * @var string
     */
    protected string $current_connection;

    /**
     * Create a manager with the injected connections map.
     *
     * @param array<string, array<string, mixed>> $connections The named
     *        connections map — each value is a connection's settings
     *        (driver, host, database, …).
     * @param string $default The name of the active connection.
     * @throws \InvalidArgumentException When the connections map is not
     *         shaped like a connection map.
     */
    public function __construct(
        protected array $connections,
        protected readonly string $default = 'default',
    ) {
        $this->current_connection = $default;
        $this->validateConfig($connections);
    }

    /**
     * Validate the shape of the injected connections map.
     *
     * Each entry must be an array declaring a string `driver` that is
     * registered in the connector registry. A malformed map is a
     * configuration error, not a runtime condition — it fails fast at
     * construction time with a message that names the problem, rather than
     * surfacing later as a confusing "undefined array key" or a null
     * connector lookup.
     *
     * @param array<string, mixed> $connections The injected connections map.
     * @throws \InvalidArgumentException When the map is not shaped like a
     *         connection map.
     */
    private function validateConfig(array $connections): void
    {
        foreach ($connections as $_ => $connectionConfig) {
            $this->validConnectionConfig($connectionConfig);
        }
    }

    /**
     * Validate the shape of a single named connection's config.
     *
     * Every connection must be an array declaring a string `driver` that is
     * registered in the connector registry. The driver-specific fields are
     * validated by the connector itself via {@see ConnectorInterface::validConfig()}.
     *
     * @param mixed $config The raw connection config value from the
     *        connections map.
     * @throws \InvalidArgumentException When the connection config is not an
     *         array, does not declare a string `driver`, or names a driver
     *         with no registered connector.
     */
    private function validConnectionConfig(mixed $config): void
    {
        if (!is_array($config)) {
            throw new \InvalidArgumentException(
                'Each connection must be an array of settings; got ' . get_debug_type($config) . '.'
            );
        }

        if (!isset($config['driver']) || !is_string($config['driver']) || $config['driver'] === '') {
            throw new \InvalidArgumentException(
                'Each connection must declare a non-empty string "driver"; got '
                    . (isset($config['driver']) ? get_debug_type($config['driver']) : 'nothing')
                    . '.'
            );
        }

        $connectorClass = $this->connectors[$config['driver']] ?? null;
        if ($connectorClass === null) {
            throw new \InvalidArgumentException(
                "No connector registered for [{$config['driver']}]. "
                    . "Register one via extendConnector('{$config['driver']}', SomeConnector::class)."
            );
        }

        (new $connectorClass())->validConfig($config);
    }

    /**
     * Get a connection by name, building and caching it on first use.
     *
     * @param string|null $name The connection name; defaults to the
     *        current connection.
     * @return ConnectionInterface The resolved connection.
     */
    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->current_connection;
        return $this->resolved[$name] ??= $this->makeConnection($this->connections[$name]);
    }

    /**
     * Register a new named connection at runtime.
     *
     * The config is validated the same way as the constructor-injected
     * map, so a malformed entry fails fast here rather than later as a
     * confusing connector lookup. Adding a connection that already exists
     * is a configuration error — it would silently shadow the original
     * config, so it throws instead.
     *
     * @param string $name The connection name.
     * @param array<string, mixed> $connection The connection settings
     *        (driver, host, database, …).
     * @throws \InvalidArgumentException When the config is invalid or a
     *         connection with that name already exists.
     */
    public function addConnection(string $name, array $connection): void
    {
        if (isset($this->connections[$name])) {
            throw new \InvalidArgumentException(
                "Connection [{$name}] is already defined."
            );
        }

        $this->validConnectionConfig($connection);
        $this->connections[$name] = $connection;
    }

    /**
     * Build a connection from its config via the registered connector.
     *
     * @param array<string, mixed> $config The connection config.
     * @return ConnectionInterface The built connection.
     * @throws \InvalidArgumentException When no connector is registered
     *         for the config's driver.
     */
    protected function makeConnection(array $config): ConnectionInterface
    {
        $driver = $config['driver'];
        $connectorClass = $this->connectors[$driver] ?? null;

        if ($connectorClass === null) {
            throw new \InvalidArgumentException(
                "No connector registered for [{$driver}]. "
                    . "Register one via extendConnector('{$driver}', SomeConnector::class)."
            );
        }

        return (new $connectorClass())->connect($config);
    }

    /**
     * Whether a named connection exists in the connections map.
     *
     * @param string $name The connection name.
     * @return bool True when the connection is configured.
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * The name of the active connection.
     *
     * @return string The active connection name.
     */
    public function currentConnection(): string
    {
        return $this->current_connection;
    }

    /**
     * Run a callback with a different active connection, restoring the
     * previous one afterwards.
     *
     * @template T
     * @param string $name The connection name to use inside the callback.
     * @param \Closure(): T $callback The work to run.
     * @return T Whatever the callback returns.
     */
    public function usingConnection(string $name, \Closure $callback): mixed
    {
        $previous = $this->current_connection;
        $this->current_connection = $name;
        try {
            return $callback();
        } finally {
            $this->current_connection = $previous;
        }
    }

    /**
     * Register a connector class for a driver.
     *
     * @param string $driver The driver name (e.g. 'sqlite').
     * @param class-string<ConnectorInterface> $connectorClass The connector
     *        class, which must implement {@see ConnectorInterface}.
     * @throws \InvalidArgumentException When the class does not implement
     *         {@see ConnectorInterface}.
     */
    public function extendConnector(string $driver, string $connectorClass): void
    {
        if (!is_subclass_of($connectorClass, ConnectorInterface::class)) {
            throw new \InvalidArgumentException(
                "Connector [{$connectorClass}] must implement " . ConnectorInterface::class . '.'
            );
        }

        $this->connectors[$driver] = $connectorClass;
    }
}
