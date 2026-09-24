<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;

/**
 * The shared filter vocabulary, instance-shaped.
 *
 * The where-family is not redefined here — this trait composes
 * {@see FiltersWhere}. What it adds is the wrapper-only surface:
 * `orderBy`/`limit`/`offset`/`select`/`groupBy`/`having` — abstract, not
 * where-derivable. The natural consumers are objects that wrap a builder
 * (a Relation holds one) and return a new wrapper per call.
 */
trait FiltersQuery
{
    use FiltersWhere;

    /**
     * Add an order-by clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  SortDirection|string  $direction
     * @return static
     */
    abstract public function orderBy(string|\BlueprintAU\Radiant\Database\Query\Expression $column, SortDirection|string $direction = SortDirection::Asc): static;

    /**
     * Set the maximum number of rows to return.
     *
     * @param  int  $limit
     * @return static
     */
    abstract public function limit(int $limit): static;

    /**
     * Set the number of rows to skip.
     *
     * @param  int  $offset
     * @return static
     */
    abstract public function offset(int $offset): static;

    /**
     * Set an explicit column selection.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate  ...$columns
     * @return static
     */
    abstract public function select(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns): static;

    /**
     * Group by columns (for aggregate + select combos).
     *
     * @param  string|array<int, string>  $columns
     * @return static
     */
    abstract public function groupBy(string|array $columns): static;

    /**
     * Filter groups after aggregation (HAVING).
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return static
     */
    abstract public function having(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column, WhereOperator|string $operator, mixed $value): static;
}
