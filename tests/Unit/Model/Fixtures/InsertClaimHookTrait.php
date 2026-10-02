<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Model;

/**
 * The insert-claim hook trait: the hook CLAIMS the insert (returns true)
 * and records the model it saw — the write never reaches the database.
 */
trait InsertClaimHookTrait
{
    /**
     * The model the hook claimed, for the test's assertion.
     *
     * @var list<Model>
     */
    public static array $claimedInserts = [];

    /**
     * Claim the INSERT — the performInsert claim arm.
     *
     * @return true
     */
    #[WriteHook(Hook::Insert)]
    public function claimInsert(): bool
    {
        self::$claimedInserts[] = $this;

        return true;
    }
}