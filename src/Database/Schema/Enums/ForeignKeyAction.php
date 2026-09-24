<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Enums;

/**
 * The referential actions a foreign key's `ON DELETE` / `ON UPDATE` clause
 * can take.
 */
enum ForeignKeyAction: string
{
    /** Delete/update the child rows too. */
    case Cascade = 'CASCADE';

    /** Set the child columns to `NULL` (they must be nullable). */
    case SetNull = 'SET NULL';

    /** Refuse the delete/update (error, transaction-compatible). */
    case Restrict = 'RESTRICT';

    /** Refuse the delete/update (SQL-standard variant). */
    case NoAction = 'NO ACTION';

    /** Set the child columns to their declared default. */
    case SetDefault = 'SET DEFAULT';

    /**
     * Resolve a string to a case, failing fast on anything else.
     *
     * @param  string  $action
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromChecked(string $action): self
    {
        $normalized = strtoupper(str_replace(['-', '_'], ' ', trim($action)));

        return self::tryFrom($normalized)
            ?? throw new \InvalidArgumentException(
                "A foreign key action must be one of CASCADE, SET NULL, RESTRICT, NO ACTION, or SET DEFAULT; got [{$action}]."
            );
    }
}
