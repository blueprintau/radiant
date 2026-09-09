<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\CompositeIndex;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;

/**
 * The composite-constraint model — every class-level constraint family.
 */
#[Unique(columns: ['country', 'tracking'])]
#[CompositeIndex(columns: ['country', 'shippedAt'])]
#[ForeignKey(columns: ['regionId', 'country'], references: 'geo_regions', referencesColumns: ['id', 'country'], onDelete: ForeignKeyAction::Cascade)]
#[Table(name: 'shipments')]
class Shipment extends Model
{
    /**
     * The primary key.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region id — part of the composite FK.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    /**
     * The country code — part of the composite unique, index, and FK.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;

    /**
     * The tracking number — part of the composite unique.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $tracking;

    /**
     * The ship date — part of the composite index.
      *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?\Carbon\Carbon $shippedAt;
}
