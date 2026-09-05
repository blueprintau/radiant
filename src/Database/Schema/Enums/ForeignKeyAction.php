<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Enums;

/**
 * The referential actions a foreign key's `ON DELETE` / `ON UPDATE` clause
 * can take.
 *
 * The action text is interpolated verbatim into the compiled DDL, so it is
 * validated through {@see ForeignKeyAction::fromChecked()} at the API
 * boundary — a raw string here is a SQL-injection sink, not a convenience.
 * Using an enum at the call site makes an invalid action a static-analysis
 * error instead of a runtime throw.
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
     * Case-insensitive and space-insensitive, so `'cascade'`, `'CASCADE'`,
     * and `'set null'` all resolve to their canonical case.
     *
     * @param string $action The raw action string.
     * @return self The matching case.
     * @throws \InvalidArgumentException When the string is not a referential action.
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
