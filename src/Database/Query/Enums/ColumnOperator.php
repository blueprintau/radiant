<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The comparison operators a column-to-column comparison can use — the
 * `whereColumn()` and join-condition operators.
 *
 * Column-to-column comparisons are narrower than value comparisons: no
 * `IN`/`BETWEEN`/null forms, which take values rather than a second column.
 * Each case carries the SQL operator text it renders as. Input is validated
 * through {@see fromChecked()}, because the operator is interpolated verbatim
 * between two identifiers in the compiled SQL — a raw string here is a
 * SQL-injection sink, not a convenience.
 */
enum ColumnOperator: string
{
    /** Equality (`=`). */
    case Eq = '=';

    /** Inequality (`!=`). */
    case NotEq = '!=';

    /** Less than (`<`). */
    case Lt = '<';

    /** Less than or equal (`<=`). */
    case LtEq = '<=';

    /** Greater than (`>`). */
    case Gt = '>';

    /** Greater than or equal (`>=`). */
    case GtEq = '>=';

    /**
     * Resolve a string to a case, failing fast on anything else.
     *
     * Accepts the symbolic operators case-sensitively and the word forms
     * case-insensitively, mirroring how {@see WhereOperator::from()} is used
     * by `where()`.
     *
     * @param string $operator The raw operator string.
     * @return self The matching case.
     * @throws \InvalidArgumentException When the string is not a column operator.
     */
    public static function fromChecked(string $operator): self
    {
        return self::tryFrom($operator)
            ?? self::tryFrom(strtoupper($operator))
            ?? throw new \InvalidArgumentException(
                "Invalid column comparison operator [{$operator}]. Expected one of: =, !=, <, <=, >, >=."
            );
    }
}
