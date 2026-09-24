<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The boolean connector between where clauses.
 */
enum WhereBoolean: string
{
    /** Logical AND. */
    case And = 'and';

    /** Logical OR. */
    case Or = 'or';
}