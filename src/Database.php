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
    private static ?DatabaseManager $manager = null;

    /**
     * Inject the manager that backs every static call.
     *
     * @param  DatabaseManager  $manager
     */
    public static function setManager(DatabaseManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * The injected manager, or fail when none was set.
     *
     * @return DatabaseManager
     * @throws \RuntimeException
     */
    public static function manager(): DatabaseManager
    {
        return self::$manager ?? throw new \RuntimeException('Database manager not set.');
    }

    /**
     * Get a connection by name, building and caching it on first use.
     *
     * @param  string|null  $name
     * @return ConnectionInterface
     */
    public static function connection(?string $name = null): ConnectionInterface
    {
        return self::manager()->connection($name);
    }

    /**
     * The active connection, narrowed to a SQL connection.
     *
     * @param  string|null  $name
     * @return SqlConnection
     * @throws UnsupportedFeatureException
     */
    public static function sqlConnection(?string $name = null): SqlConnection
    {
        return self::manager()->sqlConnection($name);
    }

    /**
     * Run a callback with a different active connection, restoring the
     * previous one afterwards.
     *
     * @template T
     * @param  string  $name
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function usingConnection(string $name, \Closure $callback): mixed
    {
        return self::manager()->usingConnection($name, $callback);
    }

    /**
     * Start a fluent query against a table on the active connection.
     *
     * @param  string  $name
     * @return QueryBuilder
     */
    public static function table(string $name): QueryBuilder
    {
        return self::connection()->table($name);
    }

    /**
     * Run a raw SQL query and return every matching row as an object.
     *
     * @param  string  $query
     * @param  array<string|int, mixed>  $args
     * @return Collection<int,\stdClass>
     */
    public static function select(string $query, array $args = []): Collection
    {
        return self::sqlConnection()->selectSql($query, $args);
    }

    /**
     * Run a raw SQL statement that returns no result set.
     *
     * @param  string  $query
     * @param  array<string|int, mixed>  $args
     */
    public static function statement(string $query, array $args = []): void
    {
        self::sqlConnection()->statement($query, $args);
    }

    /**
     * Run a raw SQL statement and return how many rows it affected.
     *
     * @param  string  $query
     * @param  array<string|int, mixed>  $args
     * @return int
     */
    public static function affectingStatement(string $query, array $args = []): int
    {
        return self::sqlConnection()->affectingStatement($query, $args);
    }
}
