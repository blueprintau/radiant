<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The shape of a where clause stored on the builder.
 */
enum WhereType: string
{
    /** A comparison like `column = ?` — keys: column, operator, value, boolean. */
    case Basic = 'basic';

    /** A range like `column between ? and ?` — keys: column, operator, value, boolean. */
    case Between = 'between';

    /** A null check like `column is null` — keys: column, operator, boolean. */
    case Null = 'null';

    /** A raw SQL condition — keys: sql, boolean. */
    case Raw = 'raw';

    /** A column-to-column comparison — keys: first, operator, second, boolean. */
    case Column = 'column';

    /** A nested group of wheres — keys: query, boolean. */
    case Nested = 'nested';

    /**
     * An `EXISTS`/`NOT EXISTS` subquery — keys: query, negated, boolean.
     *
     * One case covers both spellings: `negated` decides whether the
     * Grammar prefixes `NOT`.
     */
    case Exists = 'exists';

    /**
     * A `column IN (SELECT …)` / `column NOT IN (SELECT …)` subquery —
     * keys: column, query, negated, boolean.
     *
     * One case covers both spellings: `negated` decides whether the
     * Grammar inserts `NOT`.
     */
    case InSub = 'in-sub';
}