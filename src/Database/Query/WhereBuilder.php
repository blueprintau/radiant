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
 * A nested group is a filter, not a query: it cannot JOIN, select, order,
 * or page. This class exposes ONLY the where-family, so a callback cannot
 * reach for structure a group has no place declaring.
 *
 * It holds no clauses itself — it delegates everything to the underlying
 * query builder, where the clause list, bindings, and validation already
 * live. That keeps one source of truth: no duplicated validation, no
 * merge step, and no drift when a clause shape changes.
 *
 * **The builder is immutable like the query builder it wraps.** Every
 * method returns a NEW WhereBuilder — the original is never modified. In
 * a `whereNested()` callback, RETURN the result; a discarded return is a
 * no-op.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
final class WhereBuilder
{
    use FiltersWhere;

    /**
     * Create a builder over the given query.
     *
     * @param QueryBuilder $query The builder the clauses land on.
     */
    public function __construct(
        private readonly QueryBuilder $query,
    ) {
    }

    /**
     * Add a where clause — every other filter funnels into this.
     *
     * @param string|Expression $column The column to compare — or a raw SQL
     *        fragment wrapped in an Expression.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW builder with the clause; the original is unchanged.
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
     * Add a nested where group — a parenthesized set of conditions.
     *
     * @param callable(WhereBuilder): WhereBuilder $callback Receives the
     *        group's builder and RETURNS the constrained group.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW builder with the group; the original is unchanged.
     */
    public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        return new self($this->query->whereNested($callback, $boolean));
    }

    /**
     * Add a raw SQL where clause (e.g. `lower(email) = ?`).
     *
     * @param string $sql The raw SQL condition.
     * @param array<int, mixed> $bindings The values to bind into the condition.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW builder with the clause; the original is unchanged.
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        return new self($this->query->whereRaw($sql, $bindings, $boolean));
    }

    /**
     * Add a column-to-column comparison.
     *
     * @param string $first The first column.
     * @param \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW builder with the comparison; the original is unchanged.
     */
    public function whereColumn(string $first, \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        return new self($this->query->whereColumn($first, $operator, $second, $boolean));
    }

    /**
     * The group's compiled where clauses.
     *
     * @return list<WhereClause> The where clauses.
     */
    public function getWheres(): array
    {
        return $this->query->getWheres();
    }

    /**
     * The underlying query builder this builder delegates to (internal —
     * used by whereNested()'s storage path).
     *
     * @return QueryBuilder The wrapped builder.
     */
    public function getNestedQuery(): QueryBuilder
    {
        return $this->query;
    }
}
