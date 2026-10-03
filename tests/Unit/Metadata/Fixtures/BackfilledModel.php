<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Backfill;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A valid fixture: a #[Backfill] riding a declared column — the one-time
 * value existing rows receive when the column is added.
 */
class BackfilledModel extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A NOT NULL column whose existing rows are backfilled with 'unknown'.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    #[Backfill('unknown')]
    public string $displayName;
}
