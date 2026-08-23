<?php

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Interfaces\ConnectionInterface;
use Exception;

class Database
{

    public private(set) static string $activeConnection = "default";
    public private(set) static array $connections = [];
    private static array $env = [];
    private static array $drivers = [];


    public static function env(string $key, mixed $default = null): mixed
    {
        return isset(self::$env[$key]) ? trim(self::$env[$key]) : $default;
    }

    public static function addDriver(string $driver, string $connection): void
    {
        self::$drivers[$driver] = $connection;
    }

    public static function hasDriver(string $driver): bool
    {
        return isset(self::$drivers[$driver]);
    }


    /**
     * Register a named connection from a configuration array.
     *
     * Instantiates the appropriate driver for the given config and stores it
     * in the connection pool under the given name. Does not switch the active
     * connection — use switchTo() or usingConnection() for that.
     *
     * Supported config keys:
     *   - driver   (required) — 'mysql' or 'sqlite'
     *   - host     — database host (mysql only)
     *   - database — database name or SQLite file path
     *   - username — database username (mysql only)
     *   - password — database password (mysql only)
     *   - port     — database port, defaults to 3306 (mysql only)
     *
     * @param string $name   A unique name for this connection (e.g. 'tenant', 'reporting').
     * @param array  $config Connection configuration array.
     *
     * @return void
     * @throws Exception If the driver key is missing or maps to an unregistered driver class.
     *
     * @example
     *   Database::addConnection('tenant', [
     *       'driver'   => 'mysql',
     *       'host'     => 'tenant.db.host',
     *       'database' => 'tenant_db',
     *       'username' => 'user',
     *       'password' => 'secret',
     *   ]);
     */
    public static function addConnection(string $name, array $config): void
    {
        $driverClass = self::$drivers[$config['driver']] ?? null;

        if (!$driverClass) {
            throw new Exception("[Radiant\Database] Unknown database driver: {$config['driver']}");
        }

        self::$connections[$name] = new $driverClass($config);
    }

    /**
     * Switch the active connection globally for all subsequent queries.
     *
     * All calls to getInstance() (and therefore all static query methods) will
     * use this connection until switchTo() is called again. If you only need to
     * run a scoped block of queries on a different connection, prefer
     * usingConnection() which restores the previous connection automatically.
     *
     * @param string $name The name of a previously registered connection.
     *
     * @return void
     * @throws Exception If no connection with the given name has been registered.
     *
     * @see Database::usingConnection() For scoped, automatically-restored connection switching.
     */
    public static function switchTo(string $name): void
    {
        if (!isset(self::$connections[$name])) {
            throw new Exception("[Radiant\Database] No connection registered with name: '$name'");
        }

        self::$activeConnection = $name;
    }

    /**
     * Get a specific named connection without changing the active connection.
     *
     * Useful when you need to run a one-off query on a specific connection
     * without affecting the globally active connection for the rest of the request.
     *
     * @param string $name The name of a previously registered connection.
     *
     * @return ConnectionInterface The named connection instance.
     * @throws Exception If no connection with the given name has been registered.
     */
    public static function connection(string $name = "default"): ConnectionInterface
    {
        if (!isset(self::$connections[$name])) {
            throw new Exception("[Radiant\Database] No connection registered with name: '$name'");
        }

        return self::$connections[$name];
    }

    /**
     * Run a callback on a specific named connection, then restore the previous active connection.
     *
     * This is the safest way to query a secondary database (e.g. a tenant DB) without
     * risking connection bleed into subsequent queries in the same request. The previous
     * active connection is always restored in a finally block, even if the callback throws.
     *
     * @param string   $name     The name of a previously registered connection.
     * @param callable $callback The code to execute against the named connection.
     *
     * @return mixed The return value of the callback.
     * @throws Exception If no connection with the given name has been registered.
     *
     * @example
     *   $leads = Database::usingConnection('tenant', fn() => Lead::all());
     */
    public static function usingConnection(string $name, callable $callback): mixed
    {
        if (!isset(self::$connections[$name])) {
            throw new Exception("[Radiant\Database] No connection registered with name: '$name'");
        }

        $previous = self::$activeConnection;
        self::$activeConnection = $name;

        try {
            return $callback();
        } finally {
            self::$activeConnection = $previous;
        }
    }

}