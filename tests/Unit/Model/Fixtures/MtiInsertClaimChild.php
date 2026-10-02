<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

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