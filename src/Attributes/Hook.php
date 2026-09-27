<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * The write paths a trait hook can attach to.
 */
enum Hook: string
{
    /** The INSERT path — fires before the insert payload is built. */
    case Insert = 'insert';

    /** The UPDATE path — fires before the update payload is built. */
    case Update = 'update';

    /** The claimable delete() path — a `?bool` return claims the delete. */
    case Delete = 'delete';

    /** The forceDelete() observer path — void observers only. */
    case Destroy = 'destroy';
}
