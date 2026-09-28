<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;

/**
 * Fixture: the composite-PK owner — a region identified by id + country.
 */
class CmpRegion extends Model
{
    /**
     * The region's numeric id — the FIRST composite-PK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The country code — the SECOND composite-PK column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2, primaryKey: true)]
    public string $country;

    /**
     * The region's display name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The shipments in this region (composite HasMany).
     *
     * @return HasMany<CmpShipment>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(CmpShipment::class, ['region_id', 'country']);
    }

    /**
     * The region's primary shipment (composite HasOne).
     *
     * @return \BlueprintAU\Radiant\Relations\HasOne<CmpShipment>
     */
    public function primaryShipment(): \BlueprintAU\Radiant\Relations\HasOne
    {
        return $this->hasOne(CmpShipment::class, ['region_id', 'country']);
    }
}
