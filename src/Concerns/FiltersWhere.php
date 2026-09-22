<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;

/**
 * The shared where-family vocabulary — the ONE definition of every
 * where-derived helper.
 *
 * Every helper funnels into the {@see FiltersWhere::where()} sink —
 * the ONE abstract member (plus the {@see FiltersWhere::whereNested()}
 * structural sink) — so a host implementing the two sinks gets the whole
 * vocabulary for free, and a NEW helper is added HERE once instead of
 * copy-pasted across the builders and wrapper traits.
 *
 * **Hosts are immutable**: both sinks return a NEW host (the original is
 * never modified), and every helper returns the sink's result directly —
 * a discarded helper call is a no-op. In a `whereNested()` callback,
 * RETURN the builder.
 *
 * Column parameters accept `string|Expression`: the builders' raw-column
 * path is part of the shared contract, not a builder-only extra. A wrapper
 * that genuinely cannot splice raw SQL narrows at its own sink instead.
 *
 * Consumers:
 * - {@see \BlueprintAU\Radiant\Database\Query\QueryBuilder} — implements
 *   the sinks with full validation/binding machinery.
 * - {@see \BlueprintAU\Radiant\Database\Query\WhereBuilder} — the nested
 *   group facade; delegates both sinks to its owning builder.
 * - {@see FiltersQuery} — composes this trait and re-abstracts the sinks
 *   so a wrapping consumer (a Relation) stays the return type.
 *
 * The static twin {@see FiltersStaticQuery} mirrors this vocabulary by
 * hand: a PHP trait method cannot be static AND instance at once, so the
 * static forwarders cannot share this code.
 */
trait FiltersWhere
{
    /**
     * Add a where clause — the single sink every other filter funnels into.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to compare — or a raw SQL fragment wrapped in an Expression.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW host with the clause; the original is unchanged.
     */
    abstract public function where(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static;

    /**
     * Add a nested where group — the second sink (structural, not
     * where-derivable: it wraps a parenthesized group around fresh clauses).
     *
     * @param callable(WhereBuilder): WhereBuilder $callback Receives the
     *        group's where-family facade and RETURNS the constrained group.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW host with the group; the original is unchanged.
     */
    abstract public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static;

    /**
     * Add a nested where group connected by AND — the explicit-naming form.
     *
     * @param callable(WhereBuilder): WhereBuilder $callback Receives the
     *        group's where-family facade and RETURNS the constrained group.
     * @return static A NEW host with the group; the original is unchanged.
     */
    public function whereNestedGroup(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::And);
    }

    /**
     * Add an OR-connected nested where group.
     *
     * @param callable(WhereBuilder): WhereBuilder $callback Receives the
     *        group's where-family facade and RETURNS the constrained group.
     * @return static A NEW host with the group; the original is unchanged.
     */
    public function orWhereNested(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::Or);
    }

    /**
     * Add an `or where` clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The host (chainable).
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
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
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
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
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
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
     */
    public function whereNull(string|\BlueprintAU\Radiant\Database\Query\Expression $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Null, null, $boolean);
    }

    /**
     * Add a `where not null` clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
     */
    public function whereNotNull(string|\BlueprintAU\Radiant\Database\Query\Expression $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotNull, null, $boolean);
    }

    /**
     * Add a `where between` clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
     */
    public function whereBetween(string|\BlueprintAU\Radiant\Database\Query\Expression $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Between, $range, $boolean);
    }

    /**
     * Add a `where not between` clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
     */
    public function whereNotBetween(string|\BlueprintAU\Radiant\Database\Query\Expression $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Add a `where like` clause — the pattern is a bound value (`%`/`_`
     * are the wildcards; everything else matches literally).
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param string $pattern The LIKE pattern (e.g. `'%@example.com'`).
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
     */
    public function whereLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Like, $pattern, $boolean);
    }

    /**
     * Add an OR-connected `where like` clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param string $pattern The LIKE pattern.
     * @return static The host (chainable).
     */
    public function orWhereLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern): static
    {
        return $this->where($column, WhereOperator::Like, $pattern, WhereBoolean::Or);
    }

    /**
     * Add a `where not like` clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column to test.
     * @param string $pattern The LIKE pattern to exclude.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The host (chainable).
     */
    public function whereNotLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotLike, $pattern, $boolean);
    }
}
