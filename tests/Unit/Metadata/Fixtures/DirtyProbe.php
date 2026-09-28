<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Tests\Support\ProbesDirty;

/**
 * A probe exposing the protected dirty-tracking surface of {@see User} for
 * hydration-stability assertions.
 *
 * A behavior-only subclass (rule 1) — same table, same columns, no new
 * declarations — so its metadata resolves to `users` and it hydrates
 * identically to its parent.
 */
class DirtyProbe extends User
{
    use ProbesDirty;
}
