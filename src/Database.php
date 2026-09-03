<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * Static facade over the active {@see DatabaseManager}.
 *
 * Provides a global, convenient entry point for the most common database
 * operations — connections, fluent queries, and raw SQL — without having
 * to thread a manager instance through your code. The manager is injected
 * once via {@see setManager()} (typically at application bootstrap) and
 * every static call delegates to it.
 *
 * SQL-only operations ({@see select()}, {@see statement()} and
 * {@see affectingStatement()}) require the active connection to be a
 * {@see SqlConnection}; anything else throws an
 * {@see UnsupportedFeatureException}.
 */
final class Database
{
    /**
     * The injected manager backing every static call.
     *
     * @var ?DatabaseManager
     */
    public private(set) static ?DatabaseManager $manager = null;

    /**
     * Inject the manager that backs every static call.
     *
     * @param DatabaseManager $manager The manager to use.
     */
    public static function setManager(DatabaseManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Get a connection by name, building and caching it on first use.
     *
     * @param string|null $name The connection name; defaults to the
     *        current connection.
     * @return ConnectionInterface The resolved connection.
     */
    public static function connection(?string $name = null): ConnectionInterface
    {
        return self::manager()->connection($name);
    }

    /**
     * Start a fluent query against a table on the active connection.
     *
     * @param string $name The table name (or fully-qualified identifier).
     * @return QueryBuilder A new query builder, pre-bound to the table.
     */
    public static function table(string $name): QueryBuilder
    {
        return self::connection()->table($name);
    }

    /**
     * Run a raw SQL query and return every matching row as an object.
     *
     * Use this for ad-hoc queries that don't fit the fluent builder. Values
     * are bound through the codec, so datetimes and other types are adapted
     * to the dialect automatically.
     *
     * @param string $query The raw SQL to run.
     * @param array<string|int, mixed> $args The values to bind, keyed by
     *        column (named) or position (unnamed).
     * @return Collection<int,\stdClass> The matching rows, each as an object.
     */
    public static function select(string $query, array $args = []): Collection
    {
        return self::sql()->selectSql($query, $args);
    }

    /**
     * Run a raw SQL statement that returns no result set.
     *
     * Use this for schema changes and other statements where you don't care
     * about the outcome beyond whether it succeeded.
     *
     * @param string $query The raw SQL to run.
     * @param array<string|int, mixed> $args The values to bind, keyed by
     *        column (named) or position (unnamed).
     */
    public static function statement(string $query, array $args = []): void
    {
        self::sql()->statement($query, $args);
    }

    /**
     * Run a raw SQL statement and return how many rows it affected.
     *
     * Use this for INSERT, UPDATE, DELETE and similar statements where the
     * affected-row count matters.
     *
     * @param string $query The raw SQL to run.
     * @param array<string|int, mixed> $args The values to bind, keyed by
     *        column (named) or position (unnamed).
     * @return int How many rows the statement affected.
     */
    public static function affectingStatement(string $query, array $args = []): int
    {
        return self::sql()->affectingStatement($query, $args);
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
    public static function usingConnection(string $name, \Closure $callback): mixed
    {
        return self::manager()->usingConnection($name, $callback);
    }

    /**
     * The active connection, narrowed to a SQL connection.
     *
     * @return SqlConnection The active connection.
     * @throws UnsupportedFeatureException When the active connection is not
     *         a {@see SqlConnection}.
     */
    private static function sql(): SqlConnection
    {
        $connection = self::manager()->connection();
        if (!$connection instanceof SqlConnection) {
            throw new UnsupportedFeatureException('The active connection is not a SQL connection.');
        }

        return $connection;
    }

    /**
     * The injected manager, or fail when none was set.
     *
     * @return DatabaseManager The injected manager.
     * @throws \RuntimeException When no manager has been injected yet.
     */
    private static function manager(): DatabaseManager
    {
        return self::$manager ?? throw new \RuntimeException('Database manager not set.');
    }
}
