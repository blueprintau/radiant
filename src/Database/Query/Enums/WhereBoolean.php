<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The boolean connector between where clauses.
 *
 * `And` renders as `and`, `Or` renders as `or`. The first clause in a group
 * carries no connector.
 */
enum WhereBoolean: string
{
    /** Logical AND. */
    case And = 'and';

    /** Logical OR. */
    case Or = 'or';
}