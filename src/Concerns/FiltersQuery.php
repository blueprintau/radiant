<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;

/**
 * The shared filter vocabulary, instance-shaped.
 *
 * The where-family is NOT redefined here — this trait COMPOSES
 * {@see FiltersWhere}, the one definition of the two sinks and every
 * where-derived helper, shared with the builders themselves. A host
 * therefore implements the sinks once (the trait re-abstracts nothing:
 * the composition forwards the abstract sinks through, and the concrete
 * signature where the Expression column type lives is the shared one).
 *
 * What this trait ADDS over the where-family is the wrapper-only surface:
 * `orderBy`/`limit`/`offset`/`select`/`groupBy`/`having` — abstract, not
 * where-derivable, so the implementer owns them (typically a one-line
 * delegate to its wrapped builder).
 *
 * The natural consumers are objects that WRAP a builder (a Relation holds
 * one; a collection could too) — the implementations delegate to the
 * wrapped query and return `$this` so the wrapper keeps chaining. The
 * vocabulary covers the whole filter surface: where-family + ordering +
 * paging + select/groupBy/having. Joins stay builder-only (they are
 * structure, not filtering).
 */
trait FiltersQuery
{
    use FiltersWhere;

    /**
     * Add an order-by clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to order by — or a raw SQL fragment wrapped in an Expression.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return static The relation (chainable).
     */
    abstract public function orderBy(string|\BlueprintAU\Radiant\Database\Query\Expression $column, SortDirection|string $direction = SortDirection::Asc): static;

    /**
     * Set the maximum number of rows to return.
     *
     * @param int $limit The row limit.
     * @return static The relation (chainable).
     */
    abstract public function limit(int $limit): static;

    /**
     * Set the number of rows to skip.
     *
     * @param int $offset The number of rows to skip.
     * @return static The relation (chainable).
     */
    abstract public function offset(int $offset): static;

    /**
     * Set an explicit column selection.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return static The relation (chainable).
     */
    abstract public function select(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns): static;

    /**
     * Group by columns (for aggregate + select combos).
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return static The relation (chainable).
     */
    abstract public function groupBy(string|array $columns): static;

    /**
     * Filter groups after aggregation (HAVING).
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The relation (chainable).
     */
    abstract public function having(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column, WhereOperator|string $operator, mixed $value): static;
}
