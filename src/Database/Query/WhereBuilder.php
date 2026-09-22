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
 * or page. This class exposes ONLY the where-family — the same funnel shape
 * as the query builder, and now the SAME trait: the shared
 * {@see FiltersWhere} vocabulary over the {@see WhereBuilder::where()}
 * sink — so a callback cannot reach for structure a group has no place
 * declaring, and every helper stays in lockstep with the builders by
 * construction.
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
 * **The facade is immutable like the builder it wraps.** Every method
 * returns a NEW WhereBuilder over the new builder the sink produced — the
 * original is never modified. In a `whereNested()` callback, RETURN the
 * result; a discarded return is a no-op.
 *
 * The where-clause shapes are the query builder's discriminated unions
 * (consumed verbatim by the Grammar and portable connections) — this class
 * never re-shapes them.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
final class WhereBuilder
{
    use FiltersWhere;

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
     * @param string|Expression $column The column to compare — or a raw SQL
     *        fragment wrapped in an Expression.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW facade over the new builder; the original is unchanged.
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
     * Add a nested where group — the trait's second sink. Delegates to the
     * owning builder, so the group's clauses land on it like any other
     * filter and merge with the parent query's wheres at compile time.
     *
     * @param callable(WhereBuilder): WhereBuilder $callback Receives the
     *        group's where-family facade and RETURNS the constrained group.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW facade over the new builder; the original is unchanged.
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
     * @return static A NEW facade over the new builder; the original is unchanged.
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
     * @return static A NEW facade over the new builder; the original is unchanged.
     */
    public function whereColumn(string $first, \BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        return new self($this->query->whereColumn($first, $operator, $second, $boolean));
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

    /**
     * The backing builder this facade delegates to — whereNested()'s
     * storage path. NOT part of the callback contract: callbacks see the
     * where-family only.
     *
     * @return QueryBuilder The wrapped builder.
     */
    public function getNestedQuery(): QueryBuilder
    {
        return $this->query;
    }
}
