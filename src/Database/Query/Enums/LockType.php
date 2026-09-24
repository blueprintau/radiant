<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The row-lock modes a query builder can request.
 */
enum LockType: string
{
    /** `FOR UPDATE` — lock the selected rows against concurrent writes. */
    case Update = 'update';

    /** `LOCK IN SHARE MODE` / `FOR SHARE` — shared read lock. */
    case Shared = 'shared';
}