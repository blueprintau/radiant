<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The comparison operators a column-to-column comparison can use — the
 * `whereColumn()` and join-condition operators.
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
     * @param  string  $operator
     * @return self
     * @throws \InvalidArgumentException
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
