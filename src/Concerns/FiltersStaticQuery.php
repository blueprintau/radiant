<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * The shared filter vocabulary, static-forwarder shaped.
 *
 * The static twin of {@see FiltersQuery}: `Model`'s filter entry points are
 * STATIC (they start a query — `User::where(...)`), so the sink differs. Every `or*`/`where*` helper
 * funnels into {@see FiltersStaticQuery::where()} — the ONE abstract sink,
 * so a host overriding `where()` gets the whole where-family updated for
 * free. `orderBy`/`limit`/`offset`/`select`/`groupBy`/`having` are abstract
 * too — they are not `where`-derivable, so the implementer owns them
 * (typically `static::newQuery()->...`).
 *
 * Unlike the instance trait, static filters RETURN the builder (they
 * start a query — there is no `$this` wrapper to chain on), matching
 * Model's forwarder contract.
 *
 * Both traits exist because a PHP trait method cannot be static AND
 * instance at once — the shared shape is the code; only the sink differs.
 *
 * @phpstan-require-extends Model
 *
 * @template TModel of Model
 */
trait FiltersStaticQuery
{
    /**
     * Start a model query with a where clause — the single sink every
     * other static filter funnels into. (Intelephense models the abstract
     * trait member as a real abstract method on the consumer; the generic
     * on the builder's @return there reads `<static>` of the TRAIT, which
     * mismatches Model's `ModelQueryBuilder<static of Model>` — the
     * the `<static>` on the @return resolves to the CONSUMING class
     * (require-extends Model), so `User::where()` is typed as a User
     * builder — strict without a trait-level template dance.)
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
     * sink (whereNested is structural, not where-derivable: it wraps a
     * parenthesized group around fresh clauses).
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder;

    /**
     * Start a model query with an OR-connected nested where group.
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
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
     * @param array<int, string>|string $columns A column list, or a single column.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function select(array|string $columns = ['*']): ModelQueryBuilder;

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
     * @param string $column The column (or aggregate expression) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return ModelQueryBuilder<static> The query builder — bound to the CONSUMING class (User::where() yields a User builder).
     */
    abstract public static function having(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder;
}
