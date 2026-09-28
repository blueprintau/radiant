<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A COMPOSITE-PK model — a `foreign:` model-class reference to it must be
 * rejected (no single default column to target).
 */
#[Table(name: 'sync_composite_pk')]
class SyncCompositePk extends Model
{
    /**
     * First PK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $tenantId;

    /**
     * Second PK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $resourceId;
}
