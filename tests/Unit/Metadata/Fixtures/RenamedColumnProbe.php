<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Tests\Support\ProbesDirty;

/**
 * A probe exposing the protected dirty-tracking surface of
 * {@see RenamedColumnModel} for hydration-stability assertions.
 */
class RenamedColumnProbe extends RenamedColumnModel
{
    use ProbesDirty;
}
