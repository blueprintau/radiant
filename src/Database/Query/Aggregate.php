<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * A typed SQL aggregate: function over a column, with an optional alias.
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
     * @param  string  $column
     * @param  string|null  $alias
     * @return self
     */
    public static function count(string $column = '*', ?string $alias = null): self
    {
        return new self('count', $column, $alias);
    }

    /**
     * Maximum of a column's values.
     *
     * @param  string  $column
     * @param  string|null  $alias
     * @return self
     */
    public static function max(string $column, ?string $alias = null): self
    {
        return new self('max', $column, $alias);
    }

    /**
     * Minimum of a column's values.
     *
     * @param  string  $column
     * @param  string|null  $alias
     * @return self
     */
    public static function min(string $column, ?string $alias = null): self
    {
        return new self('min', $column, $alias);
    }

    /**
     * Sum of a column's values.
     *
     * @param  string  $column
     * @param  string|null  $alias
     * @return self
     */
    public static function sum(string $column, ?string $alias = null): self
    {
        return new self('sum', $column, $alias);
    }

    /**
     * Average of a column's values.
     *
     * @param  string  $column
     * @param  string|null  $alias
     * @return self
     */
    public static function avg(string $column, ?string $alias = null): self
    {
        return new self('avg', $column, $alias);
    }

    /**
     * Create an aggregate.
     *
     * @param  string  $function  The aggregate function name — any bare SQL identifier.
     * @param  string|Expression  $column  The column to aggregate, or an Expression for complex arguments.
     * @param  string|null  $alias  The result column name; derived from the call text when null.
     * @throws \InvalidArgumentException
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
