<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * Fixture: a soft-deleting composite-FK child — same shape as
 * CmpShipment but with the SoftDeletes trait, so the composite eager-load
 * paths can be tested against a leading trait scope.
 */
#[ForeignKey(columns: ['region_id', 'country'], references: CmpRegion::class, onDelete: ForeignKeyAction::Cascade)]
class CmpTrackedShipment extends Model
{
    use SoftDeletes;

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
     * The owning region (composite BelongsTo onto the composite-PK owner).
     *
     * @return BelongsTo<CmpRegion>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(CmpRegion::class, ['region_id', 'country']);
    }
}
