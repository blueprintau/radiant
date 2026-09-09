<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

/**
 * A probe exposing the protected dirty-tracking surface of
 * {@see RenamedColumnModel} for hydration-stability assertions.
 */
class RenamedColumnProbe extends RenamedColumnModel
{
    /**
     * The dirty columns (encoded space), exposed for tests.
     *
     * @return array<string, mixed> column => encoded value
     */
    public function dirtyColumns(): array
    {
        return $this->getDirty();
    }
}
