<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * Fixture trait: declares a bulk insert hook — the trait side of the
 * trait-vs-body collision fixture.
 */
trait VetoingTraitHook
{
    /**
     * The trait's bulk insert hook — collides with the body declaration.
     *
     * @param  array<string, mixed>  $rows  Unused — the hook never runs (the build fails first).
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected static function claimInsertRows(array &$rows): void
    {
        unset($rows);
    }
}
