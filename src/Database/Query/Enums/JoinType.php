<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The join types a query builder can apply.
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