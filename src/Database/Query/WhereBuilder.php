<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

use BlueprintAU\Radiant\Concerns\FiltersWhere;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;

/**
 * The where-family builder handed to {@see QueryBuilder::whereNested()}
 * callbacks — the vocabulary a parenthesized group may legally carry.
 *
 * It holds no clauses itself — it delegates everything to the underlying
 * query builder, where the clause list, bindings, and validation already
 * live.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
final class WhereBuilder
{
    use FiltersWhere;

    /**
     * Create a builder over the given query.
     *
     * @param  QueryBuilder  $query
     */
    public function __construct(
        private readonly QueryBuilder $query,
    ) {
    }

    /**
     * Add a where clause to the query.
     *
     * @param  string|Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function where(
        string|Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return new self($this->query->where($column, $operator, $value, $boolean));
    }

    /**
     * Add a nested where group to the query.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return new self($this->query->whereNested($callback, $boolean));
    }

    /**
     * Add an `EXISTS (subquery)` clause to the query.
     *
     * @param  QueryBuilder  $query  The existential subquery.
     * @param  WhereBoolean  $boolean
     * @param  bool  $negated  True renders `NOT EXISTS`.
     * @return static
     */
    public function whereExists(
        QueryBuilder $query,
        WhereBoolean $boolean = WhereBoolean::And,
        bool $negated = false,
    ): static {
        return new self($this->query->whereExists($query, $boolean, $negated));
    }

    /**
     * Add a `column IN (subquery)` clause to the query.
     *
     * @param  string  $column  The outer column the IN constrains.
     * @param  QueryBuilder  $query  The single-column value subquery.
     * @param  WhereBoolean  $boolean
     * @param  bool  $negated  True renders `NOT IN`.
     * @return static
     */
    public function whereInQuery(
        string $column,
        QueryBuilder $query,
        WhereBoolean $boolean = WhereBoolean::And,
        bool $negated = false,
    ): static {
        return new self($this->query->whereInQuery($column, $query, $boolean, $negated));
    }

    /**
     * Add a raw where clause to the query.
     *
     * @param  string  $sql
     * @param  array<int, mixed>  $bindings
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        return new self($this->query->whereRaw($sql, $bindings, $boolean));
    }

    /**
     * Add a where clause comparing two columns to the query.
     *
     * @param  string  $first
     * @param  \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string  $operator
     * @param  string  $second
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereColumn(string $first, \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        return new self($this->query->whereColumn($first, $operator, $second, $boolean));
    }

    /**
     * Get the where clauses of the query.
     *
     * @return list<WhereClause>
     */
    public function getWheres(): array
    {
        return $this->query->getWheres();
    }

    /**
     * Get the underlying query builder.
     *
     * @return QueryBuilder
     */
    public function getNestedQuery(): QueryBuilder
    {
        return $this->query;
    }
}
