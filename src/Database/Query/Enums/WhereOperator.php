<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The comparison operators a where or having clause can use.
 */
enum WhereOperator: string
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

    /** SQL `LIKE`. */
    case Like = 'LIKE';

    /** SQL `NOT LIKE`. */
    case NotLike = 'NOT LIKE';

    /** Membership in a list. */
    case In = 'IN';

    /** Exclusion from a list. */
    case NotIn = 'NOT IN';

    /** `IS` (rarely used directly; prefer `whereNull()`). */
    case Is = 'IS';

    /** `IS NOT` (rarely used directly; prefer `whereNotNull()`). */
    case IsNot = 'IS NOT';

    /** `BETWEEN` two values. */
    case Between = 'BETWEEN';

    /** `NOT BETWEEN` two values. */
    case NotBetween = 'NOT BETWEEN';

    /** `IS NULL`. */
    case Null = 'NULL';

    /** `IS NOT NULL`. */
    case NotNull = 'NOT NULL';

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
                "Invalid where operator [{$operator}]. Expected one of: =, !=, <, <=, >, >=, "
                . 'LIKE, NOT LIKE, IN, NOT IN, IS, IS NOT, BETWEEN, NOT BETWEEN, IS NULL, IS NOT NULL.'
            );
    }
}