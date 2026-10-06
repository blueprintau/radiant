<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * A metadata error fixture: a trait whose #[RowHook] method declares no
 * return type — bulk hooks must declare void or bool.
 */
trait RowHookUntypedReturnTrait
{
    /**
     * An untyped hook — triggers the build error.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return mixed
     */
    #[RowHook(Hook::Insert)]
    public static function onInsertUntyped(array &$rows)
    {
    }
}
