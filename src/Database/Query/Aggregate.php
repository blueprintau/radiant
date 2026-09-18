<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * A typed SQL aggregate: function over a column, with an optional alias.
 *
 * This replaces the string form (`'count(*) as total'`) that used to ride
 * the builder as raw text and be re-parsed by regex in three independent
 * places (the Grammar, the CSV connection, and the model layer's column
 * validation). The string form is GONE: aggregates are now declared as
 * objects, and every consumer reads structured properties instead of
 * parsing text.
 *
 * The trust model is explicit and split by component:
 *
 * - `$function` is validated as a BARE SQL identifier (`count`, `array_agg`,
 *   `group_concat`, any server-defined aggregate — deliberately an OPEN set,
 *   since aggregate functions are server-specific). The grammar renders it
 *   as a quoted identifier, so even a hostile name cannot splice SQL.
 * - `$column` is validated as `*` or an identifier path (`users.age`) — the
 *   same strict single-identifier shape the grammar's aggregate wrapping
 *   already enforced. Complex arguments ride {@see Expression} in a select
 *   instead.
 * - `$alias` (optional) is validated as a bare identifier; it names the
 *   result column. The read-back key for PHP-computed results is the alias
 *   when given, else the derived call text (`count(*)`, `sum(age)`) — each
 *   consumer computes that inline; there is no accessor for it.
 *
 * Static factories cover the five universal aggregates; `new Aggregate(...)`
 * covers everything server-specific.
 *
 * @package BlueprintAU\Radiant\Database\Query
 */
final class Aggregate
{
    /**
     * Count rows (or non-null values of a column).
     *
     * @param string $column The column to count — or `*` for row count.
     * @param string|null $alias The result column name.
     * @return self The aggregate.
     */
    public static function count(string $column = '*', ?string $alias = null): self
    {
        return new self('count', $column, $alias);
    }

    /**
     * Maximum of a column's values.
     *
     * @param string $column The column to aggregate.
     * @param string|null $alias The result column name.
     * @return self The aggregate.
     */
    public static function max(string $column, ?string $alias = null): self
    {
        return new self('max', $column, $alias);
    }

    /**
     * Minimum of a column's values.
     *
     * @param string $column The column to aggregate.
     * @param string|null $alias The result column name.
     * @return self The aggregate.
     */
    public static function min(string $column, ?string $alias = null): self
    {
        return new self('min', $column, $alias);
    }

    /**
     * Sum of a column's values.
     *
     * @param string $column The column to aggregate.
     * @param string|null $alias The result column name.
     * @return self The aggregate.
     */
    public static function sum(string $column, ?string $alias = null): self
    {
        return new self('sum', $column, $alias);
    }

    /**
     * Average of a column's values.
     *
     * @param string $column The column to aggregate.
     * @param string|null $alias The result column name.
     * @return self The aggregate.
     */
    public static function avg(string $column, ?string $alias = null): self
    {
        return new self('avg', $column, $alias);
    }

    /**
     * Create an aggregate.
     *
     * @param string $function The aggregate function name — any bare SQL
     *        identifier, including server-defined aggregates.
     * @param string|Expression $column The column to aggregate — `*`, an
     *        identifier path (`age`, `users.age`, optionally `distinct
     *        age`), or an {@see Expression} for complex arguments
     *        (`new Expression('price * qty')` — raw SQL, caller owns its
     *        safety, never pass user-supplied content).
     * @param string|null $alias The result column name; when null, one is
     *        derived (`count(*)`, `sum(age)` — the full call text).
     * @throws \InvalidArgumentException When any component is not the shape
     *         it must be (bare identifier / identifier path / identifier).
     */
    public function __construct(
        public readonly string $function,
        public readonly string|Expression $column,
        public readonly ?string $alias = null,
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $function) !== 1) {
            throw new \InvalidArgumentException(
                "Aggregate function must be a bare SQL identifier; got [{$function}]."
            );
        }

        // Column: `*`, an identifier path (`age`, `users.age`, `users.*`),
        // or `distinct <path>` — validated at DECLARATION. An Expression is
        // raw SQL by contract and passes through unvalidated (the same
        // trust model as a raw select).
        if (!$column instanceof Expression
            && preg_match('/^(distinct\s+)?(\*|[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*|\.\*)?)$/i', $column) !== 1
        ) {
            throw new \InvalidArgumentException(
                "Aggregate column must be *, an identifier path, `distinct <path>`, or an Expression; got [{$column}]."
            );
        }

        if ($alias !== null && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException(
                "Aggregate alias must be a bare identifier; got [{$alias}]."
            );
        }
    }
}
