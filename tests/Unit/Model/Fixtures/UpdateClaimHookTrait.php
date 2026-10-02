<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Model;

/**
 * The update-claim hook trait: the hook CLAIMS the update (returns true)
 * — the performUpdate/performMtiUpdate claim arms.
 */
trait UpdateClaimHookTrait
{
    /**
     * The models the hook claimed, for the test's assertion.
     *
     * @var list<Model>
     */
    public static array $claimedUpdates = [];

    /**
     * Claim the UPDATE — the performUpdate claim arm.
     *
     * @return true
     */
    #[WriteHook(Hook::Update)]
    public function claimUpdate(): bool
    {
        self::$claimedUpdates[] = $this;

        return true;
    }
}