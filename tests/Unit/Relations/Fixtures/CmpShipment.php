<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;

/**
 * Fixture: the composite-FK child — a shipment referencing the region's
 * composite PK via a class-string #[ForeignKey] with NULL referencesColumns
 * (defaults to the target's full composite PK).
 */
#[ForeignKey(columns: ['region_id', 'country'], references: CmpRegion::class, onDelete: ForeignKeyAction::Cascade)]
class CmpShipment extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region id — the FIRST composite-FK column.
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'region_id')]
    public ?int $regionId;

    /**
     * The country code — the SECOND composite-FK column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2, name: 'country')]
    public string $country;

    /**
     * The shipment title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;

    /**
     * The owning region (composite BelongsTo).
     *
     * @return BelongsTo<CmpRegion>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(CmpRegion::class, ['region_id', 'country']);
    }
}
