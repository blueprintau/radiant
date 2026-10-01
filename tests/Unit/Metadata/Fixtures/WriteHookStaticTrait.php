<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\WriteHook;

/**
 * A metadata error fixture: a trait whose #[WriteHook] method is static —
 * hooks must be instance methods.
 */
trait WriteHookStaticTrait
{
    /**
     * A static hook — triggers the build error.
     */
    #[WriteHook(Hook::Insert)]
    public static function onInsertStatic(): void
    {
    }
}
