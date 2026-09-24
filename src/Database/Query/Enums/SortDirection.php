<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The sort direction an `ORDER BY` clause can use.
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
     * @param  string  $direction
     * @return self
     * @throws \InvalidArgumentException
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
