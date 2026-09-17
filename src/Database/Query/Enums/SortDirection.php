<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The sort direction an `ORDER BY` clause can use.
 *
 * The direction is interpolated verbatim into the compiled SQL, so it is
 * validated through {@see SortDirection::fromChecked()} at the API boundary — a raw string
 * here is a SQL-injection sink, not a convenience.
 * Using an enum at the call site makes an invalid direction a static-analysis
 * error instead of a runtime throw. Raw SQL fragments ride {@see \BlueprintAU\Radiant\Database\Query\Expression}
 * through orderBy() — an explicit, greppable escape hatch.
 */
enum SortDirection: string
{
    /** Ascending order. */
    case Asc = 'ASC';

    /** Descending order. */
    case Desc = 'DESC';

    /**
     * Resolve a string to a case, failing fast on anything else.
     *
     * Case-insensitive, so `'desc'` and `'DESC'` both resolve.
     *
     * @param string $direction The raw direction string.
     * @return self The matching case.
     * @throws \InvalidArgumentException When the string is not `ASC` or `DESC`.
     */
    public static function fromChecked(string $direction): self
    {
        return self::tryFrom(strtoupper($direction))
            ?? throw new \InvalidArgumentException(
                "Order direction must be ASC or DESC; got [{$direction}]. "
                . 'For anything else, pass a pre-validated Expression to orderBy().'
            );
    }
}
