<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
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

/**
 * Fixture: a single-table model whose insert hook CLAIMS the write —
 * the performInsert claim arm (Model::performInsert's early return).
 */
#[Table(name: 'hook_claim_posts')]
class HookClaimPost extends Model
{
    use InsertClaimHookTrait;

    /**
     * The post's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;
}

/**
 * Fixture: a single-table model whose update hook CLAIMS the write —
 * the performUpdate claim arm.
 */
#[Table(name: 'hook_claim_updates')]
class HookClaimUpdatePost extends Model
{
    use UpdateClaimHookTrait;

    /**
     * The post's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;
}
