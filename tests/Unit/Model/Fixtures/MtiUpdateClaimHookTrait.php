<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Model;

/**
 * The MTI update-claim hook trait: the hook CLAIMS the update — the
 * performMtiUpdate claim arm.
 */
trait MtiUpdateClaimHookTrait
{
    /**
     * The models the hook claimed, for the test's assertion.
     *
     * @var list<Model>
     */
    public static array $claimedUpdates = [];

    /**
     * Claim the UPDATE — the performMtiUpdate claim arm.
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