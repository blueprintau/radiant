<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * A metadata error fixture: a trait whose #[RowHook] method declares a
 * non-void/bool return type — bulk hooks must declare void or bool.
 */
trait RowHookBadReturnTrait
{
    /**
     * A string-returning hook — triggers the build error.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return string
     */
    #[RowHook(Hook::Insert)]
    public static function onInsertStringReturn(array &$rows): string
    {
        return 'nope';
    }
}
