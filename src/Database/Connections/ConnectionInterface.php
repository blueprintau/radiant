<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * The generic database connection contract — the backend that runs a
 * structured query.
 *
 * Exposes the typed portable subset: each method takes a
 * {@see QueryBuilder} and returns exactly what the operation produces.
 * Every backend (SQL, CSV, HTTP, …) implements this interface; SQL-only
 * extras (raw SQL, transactions, schema) live on {@see SqlConnection}and
 * throw {@see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException}
 * on backends that can't support them — never silently ignored.
 *
 * @see SqlConnection
 * @see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException
 */
interface ConnectionInterface
{
    /**
     * Start a fluent query against a table, bound to this connection.
     *
     * @param string $identifier The table name (or fully-qualified identifier).
     * @return QueryBuilder A new query builder, pre-bound to the table.
     */
    public function table(string $identifier): QueryBuilder;

    /**
     * Run the query and return the matching rows.
     *
     * @param QueryBuilder $query The query to run, built via {@see table()}.
     * @return Collection<int,\stdClass> The matching rows, each as an object.
     */
    public function select(QueryBuilder $query): Collection;

    /**
     * Insert one or more rows into the table.
     *
     * Pass a single row or a list of rows. Returns how many rows were
     * inserted.
     *
     * @param QueryBuilder $query The query for the table to insert into.
     * @param array<string,mixed>|list<array<string,mixed>> $values A single
     *        row or a list of rows.
     * @return int The number of rows inserted.
     */
    public function insert(QueryBuilder $query, array $values): int;

    /**
     * Insert a single row and return its generated id.
     *
     * The id comes back in the primary key column's native PHP type: int for
     * integer keys, string for bigint keys that exceed PHP_INT_MAX. Returns
     * null when the table has no auto-increment primary key (e.g. UUID keys).
     *
     * @param QueryBuilder $query The query for the table to insert into.
     * @param array<string,mixed> $values The row to insert.
     * @return string|int|null The generated id, or null when there is none.
     */
    public function insertGetId(QueryBuilder $query, array $values): string|int|null;

    /**
     * Update the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query whose conditions select the rows to update.
     * @param array<string,mixed> $values The columns to change and their new values.
     * @return int How many rows were updated.
     */
    public function update(QueryBuilder $query, array $values): int;

    /**
     * Delete the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query whose conditions select the rows to delete.
     * @return int How many rows were deleted.
     */
    public function delete(QueryBuilder $query): int;
    
    /**
     * Whether this connection has been marked dead and should be discarded
     * by a caching layer before reuse.
     *
     * The shared failure contract behind connection caching: a backend
     * flags itself when an error indicates its transport is gone (a SQL
     * server restart, a network blip), and a caching layer (e.g.
     * {@see \BlueprintAU\Radiant\Database\DatabaseManager}) consults this
     * before handing the cached instance out again — so one transient
     * outage does not poison every subsequent request in a long-running
     * process. A backend with no meaningful failure mode (e.g. a CSV file
     * handle) returns false forever.
     *
     * @return bool True when the connection should be rebuilt before reuse.
     */
    public function isStale(): bool;

    /**
     * Mark this connection dead so a caching layer rebuilds it.
     *
     * Backends set this automatically on connection-class errors; it is on
     * the interface so a caching layer can also force-evict.
     */
    public function markStale(): void;
}
