<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The join types a query builder can apply.
 *
 * Using an enum (rather than a bare string) makes an invalid join type a
 * compile-time error instead of a silently-wrong SQL keyword.
 */
enum JoinType: string
{
    /** `INNER JOIN`. */
    case Inner = 'inner';

    /** `LEFT JOIN`. */
    case Left = 'left';

    /** `RIGHT JOIN`. */
    case Right = 'right';

    /** `CROSS JOIN`. */
    case Cross = 'cross';
}