<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: two constraints covering the SAME columns —
 * they derive the same index name, which fromMetadata() rejects as a
 * duplicate declaration.
 */
#[Unique(columns: ['regionId', 'country'])]
#[Unique(columns: ['regionId', 'country'])]
#[Table(name: 'sync_dup_constraint')]
class DuplicateConstraint extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    /**
     * The country column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;
}
