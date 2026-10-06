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
 * The static twin of {@see FiltersQuery}: `Model`'s filter entry points
 * are static (they start a query), so a PHP trait method cannot be static
 * AND instance at once — this trait mirrors the same vocabulary by hand,
 * forwarding every helper into the {@see FiltersStaticQuery::where()}
 * static sink. Static filters return the builder.
 *
 * @mixin Model
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
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    abstract public static function where(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder;

    /**
     * Start a model query with an equality where clause — sugar for
     * `where($column, '=', $value)`.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereEq(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::where($column, WhereOperator::Eq, $value, $boolean);
    }

    /**
     * Start a model query with an OR-connected equality where clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  mixed  $value
     * @return ModelQueryBuilder<static>
     */
    public static function orWhereEq(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        mixed $value,
    ): ModelQueryBuilder {
        return static::where($column, WhereOperator::Eq, $value, WhereBoolean::Or);
    }

    /**
     * Start a model query with a nested where group — the second static
     * sink; the `orWhereNested` default delegates here.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    abstract public static function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder;

    /**
     * Start a model query with a nested where group on the wrapped builder.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @return ModelQueryBuilder<static>
     */
    public static function whereNestedGroup(callable $callback): ModelQueryBuilder
    {
        return static::whereNested($callback, WhereBoolean::And);
    }

    /**
     * Start a model query with an OR-connected nested where group.
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @return ModelQueryBuilder<static>
     */
    public static function orWhereNested(callable $callback): ModelQueryBuilder
    {
        return static::whereNested($callback, WhereBoolean::Or);
    }

    /**
     * Start a model query with an `or where` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return ModelQueryBuilder<static>
     */
    public static function orWhere(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder {
        return static::where($column, $operator, $value, WhereBoolean::Or);
    }

    /**
     * Start a model query with a `where in` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array<int, mixed>  $values
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereIn(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::where($column, WhereOperator::In, $values, $boolean);
    }

    /**
     * Start a model query with a `where not in` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array<int, mixed>  $values
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereNotIn(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        array $values,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::where($column, WhereOperator::NotIn, $values, $boolean);
    }

    /**
     * Start a model query with a `where null` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereNull(string|\BlueprintAU\Radiant\Database\Query\Expression $column, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Null, null, $boolean);
    }

    /**
     * Start a model query with a `where not null` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereNotNull(string|\BlueprintAU\Radiant\Database\Query\Expression $column, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::NotNull, null, $boolean);
    }

    /**
     * Start a model query with a `where between` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array{0: mixed, 1: mixed}  $range
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereBetween(string|\BlueprintAU\Radiant\Database\Query\Expression $column, array $range, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Between, $range, $boolean);
    }

    /**
     * Start a model query with a `where not between` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  array{0: mixed, 1: mixed}  $range
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereNotBetween(string|\BlueprintAU\Radiant\Database\Query\Expression $column, array $range, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Start a model query with a `where like` clause — the pattern is a
     * bound value.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  string  $pattern
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Like, $pattern, $boolean);
    }

    /**
     * Start a model query with an OR-connected `where like` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  string  $pattern
     * @return ModelQueryBuilder<static>
     */
    public static function orWhereLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::Like, $pattern, WhereBoolean::Or);
    }

    /**
     * Start a model query with a `where not like` clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  string  $pattern
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    public static function whereNotLike(string|\BlueprintAU\Radiant\Database\Query\Expression $column, string $pattern, WhereBoolean $boolean = WhereBoolean::And): ModelQueryBuilder
    {
        return static::where($column, WhereOperator::NotLike, $pattern, $boolean);
    }

    /**
     * Start a model query with an order-by clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression  $column
     * @param  SortDirection|string  $direction
     * @return ModelQueryBuilder<static>
     */
    abstract public static function orderBy(
        string|\BlueprintAU\Radiant\Database\Query\Expression $column,
        SortDirection|string $direction = SortDirection::Asc,
    ): ModelQueryBuilder;

    /**
     * Start a model query with a row limit.
     *
     * @param  int  $limit
     * @return ModelQueryBuilder<static>
     */
    abstract public static function limit(int $limit): ModelQueryBuilder;

    /**
     * Start a model query with a row offset.
     *
     * @param  int  $offset
     * @return ModelQueryBuilder<static>
     */
    abstract public static function offset(int $offset): ModelQueryBuilder;

    /**
     * Start a model query with an explicit column selection.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate  ...$columns
     * @return ModelQueryBuilder<static>
     */
    abstract public static function select(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns): ModelQueryBuilder;

    /**
     * Start a model query grouped by one or more columns.
     *
     * @param  string|array<int, string>  $columns
     * @return ModelQueryBuilder<static>
     */
    abstract public static function groupBy(string|array $columns): ModelQueryBuilder;

    /**
     * Start a model query with a having clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return ModelQueryBuilder<static>
     */
    abstract public static function having(
        string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder;
}
