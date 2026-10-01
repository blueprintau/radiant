<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: the composite-FK RELATED model for the through chain — a leg
 * pointing at its route via the composite FK (route_id, route_country),
 * paired against CmpRoute's composite PK.
 */
class CmpLeg extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The owning route's id — the FIRST composite-FK column.
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'route_id')]
    public ?int $routeId;

    /**
     * The owning route's country — the SECOND composite-FK column.
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 2, nullable: true, name: 'route_country')]
    public ?string $routeCountry;

    /**
     * The leg's position label.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $position;
}
