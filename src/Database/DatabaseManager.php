<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connectors\ConnectorInterface;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;

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
     * @param  array<string, array<string, mixed>>  $connections
     * @param  string  $default
     * @throws \InvalidArgumentException
     */
    public function __construct(
        protected array $connections,
        protected readonly string $default = 'default',
    ) {
        $this->current_connection = $default;
        $this->validateConfig($connections);
        $this->assertConnectionExists($default);
    }

    /**
     * Validate the shape of the injected connections map.
     *
     * @param  array<string, mixed>  $connections
     * @throws \InvalidArgumentException
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
     * @param  mixed  $config  Must be an array declaring a non-empty string `driver` key.
     * @throws \InvalidArgumentException
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
     * A cached connection marked stale is discarded and rebuilt here.
     *
     * @param  string|null  $name
     * @return ConnectionInterface
     * @throws \InvalidArgumentException
     */
    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->current_connection;
        $this->assertConnectionExists($name);
        if (isset($this->resolved[$name])
            && $this->resolved[$name] instanceof SqlConnection
            && $this->resolved[$name]->isStale()) {
            $this->discardConnection($this->resolved[$name]);
            unset($this->resolved[$name]);
        }
        return $this->resolved[$name] ??= $this->makeConnection($this->connections[$name]);
    }

    /**
     * Evict resolved connection(s) from the cache.
     *
     * Evicting rolls back any open transaction on the connection first.
     *
     * @param  string|null  $name
     * @return void
     * @throws \InvalidArgumentException
     */
    public function flush(?string $name = null): void
    {
        if ($name === null) {
            foreach (array_keys($this->resolved) as $resolvedName) {
                $this->flush($resolvedName);
            }
            return;
        }

        if (!isset($this->resolved[$name])) {
            $this->assertConnectionExists($name);
            return; // not resolved — nothing to evict
        }

        if ($this->resolved[$name] instanceof SqlConnection) {
            $this->discardConnection($this->resolved[$name]);
        }
        unset($this->resolved[$name]);
    }

    /**
     * Replace a named connection's configuration and evict its instance.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $config
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setConnectionConfig(string $name, array $config): void
    {
        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException(
                "Unknown connection [{$name}]; add it via addConnection() first."
            );
        }

        $this->validConnectionConfig($config);

        $this->connections[$name] = $config;
        $this->flush($name);
    }

    /**
     * Evict one connection — a readable alias for {@see flush($name)}.
     *
     * @param  string  $name
     * @return void
     */
    public function disconnect(string $name): void
    {
        $this->flush($name);
    }

    /**
     * Best-effort cleanup before a connection is evicted.
     *
     * @param  SqlConnection  $connection
     * @return void
     */
    private function discardConnection(SqlConnection $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                break; // dead connection — the server-side transaction is gone too
            }
        }
    }

    /**
     * Fail fast when the named connection is not registered.
     *
     * @param  string  $name
     * @throws \InvalidArgumentException
     */
    private function assertConnectionExists(string $name): void
    {
        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException("Unknown connection [{$name}].");
        }
    }

    /**
     * Get a connection by name, narrowed to a SQL connection.
     *
     * @param  string|null  $name
     * @return SqlConnection
     * @throws UnsupportedFeatureException
     */
    public function sqlConnection(?string $name = null): SqlConnection
    {
        $connection = $this->connection($name);
        if (!$connection instanceof SqlConnection) {
            throw new UnsupportedFeatureException('The connection is not a SQL connection.');
        }

        return $connection;
    }

    /**
     * Register a new named connection at runtime.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $connection
     * @throws \InvalidArgumentException
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
     * @param  array<string, mixed>  $config
     * @return ConnectionInterface
     * @throws \InvalidArgumentException
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
     * @param  string  $name
     * @return bool
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * The name of the active connection.
     *
     * @return string
     */
    public function currentConnection(): string
    {
        return $this->current_connection;
    }

    /**
     * Make a named connection the active one, persistently.
     *
     * Unlike {@see usingConnection()}, the switch is not scoped — it stays
     * until changed again. Switching away from a connection holding an
     * open transaction fails fast: the transaction would be left dangling.
     *
     * @param  string  $name
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function useConnection(string $name): void
    {
        $this->assertConnectionExists($name);

        $current = $this->resolved[$this->current_connection] ?? null;
        if ($current instanceof SqlConnection && $current->transactionLevel() > 0) {
            throw new \LogicException(
                "Cannot switch away from connection [{$this->current_connection}] "
                    . "with an open transaction (level {$current->transactionLevel()}); "
                    . 'commit or roll back first.'
            );
        }

        $this->current_connection = $name;
    }

    /**
     * Run a callback with a different active connection, restoring the
     * previous one afterwards.
     *
     * Unlike {@see useConnection()}, an open transaction on the active
     * connection is allowed — the swap is always restored, so the
     * transaction stays under the caller's control.
     *
     * @template T
     * @param  string  $name
     * @param  \Closure(): T  $callback
     * @return T
     * @throws \InvalidArgumentException
     */
    public function usingConnection(string $name, \Closure $callback): mixed
    {
        $this->assertConnectionExists($name);

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
     * @param  string  $driver
     * @param  class-string<ConnectorInterface>  $connectorClass
     * @throws \InvalidArgumentException
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
