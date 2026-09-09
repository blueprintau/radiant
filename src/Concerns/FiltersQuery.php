<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * The shared filter vocabulary, instance-shaped.
 *
 * Every `or*`/`where*` helper funnels into {@see FiltersQuery::where()} —
 * the ONE abstract sink, so a host overriding `where()` gets the whole
 * vocabulary updated for free (exactly how the base QueryBuilder is built,
 * and the same pattern the static forwarders mirror in
 * {@see FiltersStaticQuery}). `orderBy`/`limit`/`offset` are abstract too —
 * they are not `where`-derivable, so the implementer owns them (typically
 * a one-line delegate to its wrapped builder).
 *
 * The natural consumers are objects that WRAP a builder (a Relation holds
 * one; a collection could too) — the implementations delegate to the
 * wrapped query and return `$this` so the wrapper keeps chaining. The
 * vocabulary covers the whole filter surface: where-family + orderBy/
 * limit/offset + select/groupBy/having. Joins stay builder-only (they are
 * structure, not filtering).
 */
trait FiltersQuery
{
    /**
     * Add a where clause — the single sink every other filter funnels into.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    abstract public function where(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static;

    /**
     * Add a nested where group — the second sink (whereNested is structural,
     * not where-derivable: it wraps a parenthesized group around fresh
     * clauses).
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    abstract public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static;

    /**
     * Add a nested where group on the wrapped builder.
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @return static The relation (chainable).
     */
    public function whereNestedGroup(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::And);
    }

    /**
     * Add an OR-connected nested where group on the wrapped builder.
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @return static The relation (chainable).
     */
    public function orWhereNested(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::Or);
    }

    /**
     * Add an `or where` clause.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The relation (chainable).
     */
    public function orWhere(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
    ): static {
        return $this->where($column, $operator, $value, WhereBoolean::Or);
    }

    /**
     * Add a `where in` clause.
     *
     * @param string $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereIn(
        string $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return $this->where($column, WhereOperator::In, $values, $boolean);
    }

    /**
     * Add a `where not in` clause.
     *
     * @param string $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereNotIn(
        string $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return $this->where($column, WhereOperator::NotIn, $values, $boolean);
    }

    /**
     * Add a `where null` clause.
     *
     * @param string $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereNull(string $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Null, null, $boolean);
    }

    /**
     * Add a `where not null` clause.
     *
     * @param string $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereNotNull(string $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotNull, null, $boolean);
    }

    /**
     * Add a `where between` clause.
     *
     * @param string $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Between, $range, $boolean);
    }

    /**
     * Add a `where not between` clause.
     *
     * @param string $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereNotBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Add an order-by clause.
     *
     * @param string $column The column to order by.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return static The relation (chainable).
     */
    abstract public function orderBy(string $column, SortDirection|string $direction = SortDirection::Asc): static;

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
     * @param array<int, string>|string $columns A column list, or a single column.
     * @return static The relation (chainable).
     */
    abstract public function select(array|string $columns = ['*']): static;

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
     * @param string $column The column (or aggregate expression) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The relation (chainable).
     */
    abstract public function having(string $column, WhereOperator|string $operator, mixed $value): static;
}
