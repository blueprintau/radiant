<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * The generic database connection contract — the backend that runs a
 * structured query.
 *
 * Every backend (SQL, CSV, HTTP, …) implements this interface; SQL-only
 * extras (raw SQL, transactions, schema) live on {@see SqlConnection} and
 * throw {@see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException}
 * on backends that can't support them.
 *
 * @see SqlConnection
 * @see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException
 */
interface ConnectionInterface
{
    /**
     * Start a fluent query against a table, bound to this connection.
     *
     * @param  string  $identifier
     * @return QueryBuilder
     */
    public function table(string $identifier): QueryBuilder;

    /**
     * Run the query and return the matching rows.
     *
     * @param  QueryBuilder  $query
     * @return Collection<int,\stdClass>
     */
    public function select(QueryBuilder $query): Collection;

    /**
     * Run the query and return the first selected column's values, positionally.
     *
     * @param  QueryBuilder  $query
     * @return Collection<int, mixed>
     */
    public function selectColumn(QueryBuilder $query): Collection;

    /**
     * Run the query and yield each matching row as it arrives.
     *
     * Consume the generator fully (or let it be garbage collected) before
     * running another query on the connection — an unfinished cursor may
     * hold the statement open.
     *
     * @param  QueryBuilder  $query
     * @return \Generator<int,\stdClass>
     */
    public function cursor(QueryBuilder $query): \Generator;

    /**
     * Insert one or more rows into the table.
     *
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>|list<array<string,mixed>>  $values
     * @return int
     */
    public function insert(QueryBuilder $query, array $values): int;

    /**
     * Insert a single row and return its generated id.
     *
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $values
     * @return string|int|null
     */
    public function insertGetId(QueryBuilder $query, array $values): string|int|null;

    /**
     * Update the rows matching the query's conditions.
     *
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $values
     * @return int
     */
    public function update(QueryBuilder $query, array $values): int;

    /**
     * Delete the rows matching the query's conditions.
     *
     * @param  QueryBuilder  $query
     * @return int
     */
    public function delete(QueryBuilder $query): int;
    
    /**
     * Whether this connection has been marked dead and should be discarded
     * by a caching layer before reuse.
     *
     * @return bool
     */
    public function isStale(): bool;

    /**
     * Mark this connection dead so a caching layer rebuilds it.
     */
    public function markStale(): void;
}
