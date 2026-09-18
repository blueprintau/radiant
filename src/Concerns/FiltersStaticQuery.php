<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;

/**
 * The shared filter vocabulary, static-forwarder shaped.
 *
 * The static twin of {@see FiltersQuery}: `Model`'s filter entry points are
 * STATIC (they start a query — `User::where(...)`), so the sink differs.
 * A PHP trait method cannot be static AND instance at once, so this trait
 * cannot compose {@see FiltersWhere} (whose helpers are instance
 * methods) — it mirrors the SAME vocabulary by hand, forwarding every
 * helper into the {@see FiltersStaticQuery::where()} static sink. When a
 * helper is added to FiltersWhere, add its static twin here.
 *
 * `orderBy`/`limit`/`offset`/`select`/`groupBy`/`having` are abstract —
 * not where-derivable, so the implementer owns them (typically
 * `static::newQuery()->...`).
 *
 * Unlike the instance trait, static filters RETURN the builder (they
 * start a query — there is no `$this` wrapper to chain on), matching
 * Model's forwarder contract.
 *
 * @phpstan-require-extends Model
 *
 * @template TModel of Model
 */
trait FiltersStaticQuery
{
    /**
     * Start a model query with a where clause — the single sink every
     * other static filter funnels into.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function where(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder;

    /**
     * Start a model query with a nested where group — the second static
     * sink; the `orWhereNested` default delegates here.
     *
     * @param callable(WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder;

    /**
     * Start a model query with a nested where group on the wrapped builder.
     *
     * @param callable(WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereNestedGroup(callable $callback): ModelQueryBuilder
    {
        return static::whereNested($callback, WhereBoolean::And);
    }

    /**
     * Start a model query with an OR-connected nested where group.
     *
     * @param callable(WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function orWhereNested(callable $callback): ModelQueryBuilder
    {
        return static::whereNested($callback, WhereBoolean::Or);
    }

    /**
     * Start a model query with an `or where` clause.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function orWhere(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder {
        return static::where($column, $operator, $value, WhereBoolean::Or);
    }

    /**
     * Start a model query with a `where in` clause.
     *
     * @param string $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereIn(
        string $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::where($column, WhereOperator::In, $values, $boolean);
    }

    /**
     * Start a model query with a `where not in` clause.
     *
     * @param string $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereNotIn(
        string $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::where($column, WhereOperator::NotIn, $values, $boolean);
    }

    /**
     * Start a model query with a `where null` clause.
     *
     * @param string $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereNull(string $column, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Null, null, $boolean);
    }

    /**
     * Start a model query with a `where not null` clause.
     *
     * @param string $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereNotNull(string $column, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::NotNull, null, $boolean);
    }

    /**
     * Start a model query with a `where between` clause.
     *
     * @param string $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Between, $range, $boolean);
    }

    /**
     * Start a model query with a `where not between` clause.
     *
     * @param string $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereNotBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Start a model query with a `where like` clause — the pattern is a
     * bound value (`%`/`_` are the wildcards; everything else matches
     * literally).
     *
     * @param string $column The column to test.
     * @param string $pattern The LIKE pattern (e.g. `'%@example.com'`).
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereLike(string $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Like, $pattern, $boolean);
    }

    /**
     * Start a model query with an OR-connected `where like` clause.
     *
     * @param string $column The column to test.
     * @param string $pattern The LIKE pattern.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function orWhereLike(string $column, string $pattern): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Like, $pattern, WhereBoolean::Or);
    }

    /**
     * Start a model query with a `where not like` clause.
     *
     * @param string $column The column to test.
     * @param string $pattern The LIKE pattern to exclude.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    public static function whereNotLike(string $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::NotLike, $pattern, $boolean);
    }

    /**
     * Start a model query with an order-by clause.
     *
     * @param string $column The column to order by.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function orderBy(
        string $column,
        SortDirection|string $direction = SortDirection::Asc,
    ): ModelQueryBuilder;

    /**
     * Start a model query with a row limit.
     *
     * @param int $limit The row limit.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function limit(int $limit): ModelQueryBuilder;

    /**
     * Start a model query with a row offset.
     *
     * @param int $offset The number of rows to skip.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function offset(int $offset): ModelQueryBuilder;

    /**
     * Start a model query with an explicit column selection.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function select(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns): ModelQueryBuilder;

    /**
     * Start a model query grouped by one or more columns.
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function groupBy(string|array $columns): ModelQueryBuilder;

    /**
     * Start a model query with a having clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function having(
        string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder;
}
