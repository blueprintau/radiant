<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;
use BlueprintAU\Radiant\Relations\HasManyThrough;
use BlueprintAU\Radiant\Relations\HasOneThrough;

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

    /**
     * The region's soft-deleting shipments (composite HasMany onto a
     * SoftDeletes model — exercises the eager-load scope composition).
     *
     * @return \BlueprintAU\Radiant\Relations\HasMany<CmpTrackedShipment>
     */
    public function trackedShipments(): \BlueprintAU\Radiant\Relations\HasMany
    {
        return $this->hasMany(CmpTrackedShipment::class, ['region_id', 'country']);
    }

    /**
     * The region's primary soft-deleting shipment (composite HasOne onto a
     * SoftDeletes model).
     *
     * @return \BlueprintAU\Radiant\Relations\HasOne<CmpTrackedShipment>
     */
    public function primaryTrackedShipment(): \BlueprintAU\Radiant\Relations\HasOne
    {
        return $this->hasOne(CmpTrackedShipment::class, ['region_id', 'country']);
    }

    /**
     * The region's legs (composite HasManyThrough): Region → Route → Leg.
     * firstKey = the route's composite FK back to the region, secondKey =
     * the leg's composite FK to the route, localKey = the region's own
     * composite PK.
     *
     * @return HasManyThrough<CmpLeg>
     */
    public function legs(): HasManyThrough
    {
        return $this->hasManyThrough(
            CmpLeg::class,
            CmpRoute::class,
            ['region_id', 'country'],
            ['route_id', 'route_country'],
            ['id', 'country'],
        );
    }

    /**
     * The region's first leg (composite HasOneThrough) — same chain, one row.
     *
     * @return HasOneThrough<CmpLeg>
     */
    public function firstLeg(): HasOneThrough
    {
        return $this->hasOneThrough(
            CmpLeg::class,
            CmpRoute::class,
            ['region_id', 'country'],
            ['route_id', 'route_country'],
            ['id', 'country'],
        );
    }
}
