<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * A metadata error fixture: a trait whose #[RowHook] method is not
 * static — bulk hooks must be static.
 */
trait RowHookNonStaticTrait
{
    /**
     * An instance hook — triggers the build error.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return void
     */
    #[RowHook(Hook::Insert)]
    public function onInsertNotStatic(array &$rows): void
    {
    }
}
