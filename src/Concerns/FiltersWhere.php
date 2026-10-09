<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;

/**
 * The shared where-family vocabulary — the one definition of every
 * where-derived helper.
 *
 * Every helper funnels into one of the abstract sink members — the
 * {@see FiltersWhere::where()} value sink plus the whereNested(),
 * whereExists() and whereInQuery() structural sinks — so a host
 * implementing the sinks gets the whole vocabulary for free. Hosts are
 * immutable: every sink returns a new host, and every helper returns the
 * sink's result directly. Column parameters accept `string|Expression`.
 *
 * Consumers:
 * - {@see \BlueprintAU\Radiant\Database\Query\QueryBuilder}
 * - {@see \BlueprintAU\Radiant\Database\Query\WhereBuilder}
 * - {@see FiltersQuery}
 */
trait FiltersWhere
{
    /**
     * Add a where clause — the single sink every other filter funnels into.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return static
     */
    abstract public function where(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static;

    /**
     * Add an equality where clause — sugar for
     * `where($column, '=', $value)`.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereEq(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return $this->where($column, WhereOperator::Eq, $value, $boolean);
    }

    /**
     * Add an OR-connected equality where clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  mixed  $value
     * @return static
     */
    public function orWhereEq(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        mixed $value,
    ): static {
        return $this->where($column, WhereOperator::Eq, $value, WhereBoolean::Or);
    }

    /**
     * Add a nested where group — the second sink.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @param  WhereBoolean  $boolean
     * @return static
     */
    abstract public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static;

    /**
     * Add a nested where group connected by AND.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @return static
     */
    public function whereNestedGroup(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::And);
    }

    /**
     * Add an OR-connected nested where group.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @return static
     */
    public function orWhereNested(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::Or);
    }

    /**
     * Add an `EXISTS (subquery)` clause to the query.
     *
     * The subquery is a caller-built builder, typically another model's
     * `newQuery()` correlated to the outer query via `whereColumn()`.
     *
     * @param  QueryBuilder  $query  The existential subquery.
     * @param  WhereBoolean  $boolean
     * @param  bool  $negated  True renders `NOT EXISTS`.
     * @return static
     */
    abstract public function whereExists(
        QueryBuilder $query,
        WhereBoolean $boolean = WhereBoolean::And,
        bool $negated = false,
    ): static;

    /**
     * Add a `NOT EXISTS (subquery)` clause.
     *
     * @param  QueryBuilder  $query  The existential subquery.
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNotExists(QueryBuilder $query, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->whereExists($query, $boolean, true);
    }

    /**
     * Add an OR-connected `EXISTS (subquery)` clause.
     *
     * @param  QueryBuilder  $query  The existential subquery.
     * @return static
     */
    public function orWhereExists(QueryBuilder $query): static
    {
        return $this->whereExists($query, WhereBoolean::Or);
    }

    /**
     * Add an OR-connected `NOT EXISTS (subquery)` clause.
     *
     * @param  QueryBuilder  $query  The existential subquery.
     * @return static
     */
    public function orWhereNotExists(QueryBuilder $query): static
    {
        return $this->whereExists($query, WhereBoolean::Or, true);
    }

    /**
     * Add a `column IN (subquery)` clause to the query.
     *
     * The subquery must select exactly one column.
     *
     * @param  string  $column  The outer column the IN constrains.
     * @param  QueryBuilder  $query  The single-column value subquery.
     * @param  WhereBoolean  $boolean
     * @param  bool  $negated  True renders `NOT IN`.
     * @return static
     */
    abstract public function whereInQuery(
        string $column,
        QueryBuilder $query,
        WhereBoolean $boolean = WhereBoolean::And,
        bool $negated = false,
    ): static;

    /**
     * Add a `column NOT IN (subquery)` clause.
     *
     * @param  string  $column  The outer column the NOT IN constrains.
     * @param  QueryBuilder  $query  The single-column value subquery.
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNotInQuery(string $column, QueryBuilder $query, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->whereInQuery($column, $query, $boolean, true);
    }

    /**
     * Add an OR-connected `column IN (subquery)` clause.
     *
     * @param  string  $column  The outer column the IN constrains.
     * @param  QueryBuilder  $query  The single-column value subquery.
     * @return static
     */
    public function orWhereInQuery(string $column, QueryBuilder $query): static
    {
        return $this->whereInQuery($column, $query, WhereBoolean::Or);
    }

    /**
     * Add an OR-connected `column NOT IN (subquery)` clause.
     *
     * @param  string  $column  The outer column the NOT IN constrains.
     * @param  QueryBuilder  $query  The single-column value subquery.
     * @return static
     */
    public function orWhereNotInQuery(string $column, QueryBuilder $query): static
    {
        return $this->whereInQuery($column, $query, WhereBoolean::Or, true);
    }

    /**
     * Add an `or where` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return static
     */
    public function orWhere(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        WhereOperator|string $operator,
        mixed $value,
    ): static {
        return $this->where($column, $operator, $value, WhereBoolean::Or);
    }

    /**
     * Add a `where in` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array<int, mixed>  $values
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereIn(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return $this->where($column, WhereOperator::In, $values, $boolean);
    }

    /**
     * Add a `where not in` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array<int, mixed>  $values
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNotIn(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return $this->where($column, WhereOperator::NotIn, $values, $boolean);
    }

    /**
     * Add a `where null` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNull(string|\BlueprintAU\Radiant\Database\Query\Expression $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Null, null, $boolean);
    }

    /**
     * Add a `where not null` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNotNull(string|\BlueprintAU\Radiant\Database\Query\Expression $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotNull, null, $boolean);
    }

    /**
     * Add a `where between` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array{0: mixed, 1: mixed}  $range
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereBetween(string|\BlueprintAU\Radiant\Database\Query\Expression $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Between, $range, $boolean);
    }

    /**
     * Add a `where not between` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array{0: mixed, 1: mixed}  $range
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNotBetween(string|\BlueprintAU\Radiant\Database\Query\Expression $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Add a `where like` clause — the pattern is a bound value.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  string  $pattern
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Like, $pattern, $boolean);
    }

    /**
     * Add an OR-connected `where like` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  string  $pattern
     * @return static
     */
    public function orWhereLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern): static
    {
        return $this->where($column, WhereOperator::Like, $pattern, WhereBoolean::Or);
    }

    /**
     * Add a `where not like` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  string  $pattern
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNotLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotLike, $pattern, $boolean);
    }
}
