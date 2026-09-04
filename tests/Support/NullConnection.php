<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * A connection stub that throws on every call.
 *
 * Compilation never touches the connection, so these methods are
 * unreachable in compile-only tests. Other test suites can extend this to
 * record calls or return canned data.
 */
class NullConnection implements ConnectionInterface
{
    /**
     * Start a fluent query against a table.
     *
     * @param string $identifier The table name.
     * @return QueryBuilder The builder.
     */
    public function table(string $identifier): QueryBuilder
    {
        throw new \LogicException('Not used in compile-only tests.');
    }

    /**
     * Run the query and return the matching rows.
     *
     * @param QueryBuilder $query The query to run.
     * @return Collection<int, \stdClass> The rows.
     */
    public function select(QueryBuilder $query): Collection
    {
        throw new \LogicException('Not used in compile-only tests.');
    }

    /**
     * Insert one or more rows.
     *
     * @param QueryBuilder $query The query.
     * @param array<string, mixed>|list<array<string, mixed>> $values The rows.
     * @return int The number of rows inserted.
     */
    public function insert(QueryBuilder $query, array $values): int
    {
        throw new \LogicException('Not used in compile-only tests.');
    }

    /**
     * The test stub has no transport to lose — never stale.
     *
     * @return bool Always false.
     */
    public function isStale(): bool
    {
        return false;
    }

    /**
     * A no-op for the test stub.
     */
    public function markStale(): void
    {
        // Nothing to mark.
    }

    /**
     * Insert a single row and return its generated id.
     *
     * @param QueryBuilder $query The query.
     * @param array<string, mixed> $values The row.
     * @return string|int|null The generated id.
     */
    public function insertGetId(QueryBuilder $query, array $values): string|int|null
    {
        throw new \LogicException('Not used in compile-only tests.');
    }

    /**
     * Update the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query.
     * @param array<string, mixed> $values The columns to change.
     * @return int How many rows were updated.
     */
    public function update(QueryBuilder $query, array $values): int
    {
        throw new \LogicException('Not used in compile-only tests.');
    }

    /**
     * Delete the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query.
     * @return int How many rows were deleted.
     */
    public function delete(QueryBuilder $query): int
    {
        throw new \LogicException('Not used in compile-only tests.');
    }
}