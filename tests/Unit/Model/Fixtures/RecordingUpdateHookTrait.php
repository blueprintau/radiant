<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * Fixture: a bulk-update hook recording the values map it receives.
 */
trait RecordingUpdateHookTrait
{
    /** @var array<string, mixed>|null The last values map seen by the hook. */
    public static array|null $seenUpdateValues = null;

    /**
     * Record the update values.
     *
     * @param  array<string, mixed>  $values
     */
    #[RowHook(Hook::Update)]
    public static function observeUpdateValues(array &$values): void
    {
        self::$seenUpdateValues = $values;
    }
}
