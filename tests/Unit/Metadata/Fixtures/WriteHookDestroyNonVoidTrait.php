<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\WriteHook;

/**
 * A metadata error fixture: a trait whose #[WriteHook(Hook::Destroy)]
 * method returns a non-void value — the hard DELETE is unclaimable.
 */
trait WriteHookDestroyNonVoidTrait
{
    /**
     * A Destroy hook returning a value — triggers the build error.
     *
     * @return bool
     */
    #[WriteHook(Hook::Destroy)]
    public function onDestroyNonVoid(): bool
    {
        return false;
    }
}
