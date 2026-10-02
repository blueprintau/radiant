<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

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

/**
 * Fixture: an MTI child whose update hook CLAIMS the write — the
 * performMtiUpdate claim arm (Model::performMtiUpdate's early return).
 */
#[Table(name: 'mti_hook_claim_children')]
class MtiHookClaimChild extends MtiUser
{
    use MtiUpdateClaimHookTrait;

    /**
     * The admin level (on the child's own table).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}

/**
 * Fixture: an MTI child whose INSERT hook CLAIMS the write — the
 * performMtiInsert claim arm (the multi-table transaction never opens).
 */
#[Table(name: 'mti_insert_claim_children')]
class MtiInsertClaimChild extends MtiUser
{
    use InsertClaimHookTrait;

    /**
     * The admin level (on the child's own table).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
