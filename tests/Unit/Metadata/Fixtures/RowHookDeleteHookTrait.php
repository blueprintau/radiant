<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * A metadata error fixture: a trait declaring #[RowHook(Hook::Delete)] —
 * bulk hooks support Insert and Update only.
 */
trait RowHookDeleteHookTrait
{
    /**
     * A delete-path hook — triggers the build error.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return void
     */
    #[RowHook(Hook::Delete)]
    public static function onDeleteRows(array &$rows): void
    {
    }
}
