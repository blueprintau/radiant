<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;

/**
 * The where-family builder handed to {@see QueryBuilder::whereNested()}
 * callbacks — the vocabulary a parenthesized group may legally carry.
 *
 * A nested group is a filter, not a query: it cannot JOIN, select, order,
 * or page. This class exposes ONLY the where-family (the same funnel shape
 * as the query builder: one {@see WhereBuilder::where()} sink, the `or*`/
 * `where*` helpers derived over it), so a callback cannot reach for
 * structure a group has no place declaring.
 *
 * Why it doesn't OWN clauses itself (Q: "should it just add the binding?
 * does it actually need the Query when we're creating a new one anyway?"):
 * yes it does — deliberately. The clause list (`wheres[]`), the per-category
 * binding machinery, and every validation (operator resolution, In's
 * non-empty guard, Expression/ToSqlValue extraction) live on the query
 * builder. Owning none of that is the point: the facade adds NO second
 * storage, NO duplicated validation, and NO merge step at `whereNested()` —
 * the clauses are already where the Grammar and portable connections read
 * them, in the shape they expect. "Just adding the binding" would duplicate
 * the validator half of `where()` (the source of truth) and drift the day
 * a clause shape changes. The facade is thin BY DESIGN — the type IS the
 * constraint; the delegation IS the correctness.
 *
 * The where-clause shapes are the query builder's discriminated unions
 * (consumed verbatim by the Grammar and portable connections) — this class
 * never re-shapes them.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
final class WhereBuilder
{
    /**
     * Create a facade over the owning builder's where sink.
     *
     * The owning builder is an implementation detail of group storage —
     * it stays private and is NOT part of the callback contract. Callbacks
     * see the where-family only.
     *
     * @param QueryBuilder $query The builder the clauses land on.
     */
    public function __construct(
        private readonly QueryBuilder $query,
    ) {
    }

    /**
     * Add a where clause — the single sink every other filter funnels into.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The builder (chainable).
     */
    public function where(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        $this->query->where($column, $operator, $value, $boolean);

        return $this;
    }

    /**
     * Add an `or where` clause.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The builder (chainable).
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
     * @return static The builder (chainable).
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
     * @return static The builder (chainable).
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
     * @return static The builder (chainable).
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
     * @return static The builder (chainable).
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
     * @return static The builder (chainable).
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
     * @return static The builder (chainable).
     */
    public function whereNotBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Add a raw SQL where clause (e.g. `lower(email) = ?`).
     *
     * @param string $sql The raw SQL condition.
     * @param array<int, mixed> $bindings The values to bind into the condition.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The builder (chainable).
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        $this->query->whereRaw($sql, $bindings, $boolean);

        return $this;
    }

    /**
     * Add a column-to-column comparison.
     *
     * @param string $first The first column.
     * @param \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The builder (chainable).
     */
    public function whereColumn(string $first, \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        $this->query->whereColumn($first, $operator, $second, $boolean);

        return $this;
    }

    /**
     * The group's compiled where clauses — what the Grammar and portable
     * connections consume (parenthesization is the caller's job).
     *
     * @return list<WhereClause> The where clauses.
     */
    public function getWheres(): array
    {
        return $this->query->getWheres();
    }
}
