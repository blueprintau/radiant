<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model whose #[ForeignKey] declares DEFERRABLE INITIALLY DEFERRED —
 * the Postgres circular-seed option flows through fromMetadata() into
 * the FK shape (compiled only by the Postgres grammar).
 */
#[ForeignKey(columns: ['ownerId'], references: 'sync_options', deferrable: true, initiallyDeferred: true)]
#[Table(name: 'sync_deferrable')]
class DeferrableFk extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The FK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $ownerId;
}
