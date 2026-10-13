<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\MorphDelete;
use BlueprintAU\Radiant\Attributes\MorphDeleteAction;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: TWO #[MorphDelete] declarations for the same
 * (related, morphName) pair.
 */
#[MorphDelete(MorphDeletePostFixture::class, 'attachable')]
#[MorphDelete(MorphDeletePostFixture::class, 'attachable', MorphDeleteAction::Delete)]
class MdDuplicateParent extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;
}
