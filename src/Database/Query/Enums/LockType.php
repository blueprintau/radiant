<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The row-lock modes a query builder can request.
 *
 * Using an enum (rather than a bare string) makes an invalid lock mode a
 * compile-time error instead of a silently-dropped lock.
 */
enum LockType: string
{
    /** `FOR UPDATE` — lock the selected rows against concurrent writes. */
    case Update = 'update';

    /** `LOCK IN SHARE MODE` / `FOR SHARE` — shared read lock. */
    case Shared = 'shared';
}