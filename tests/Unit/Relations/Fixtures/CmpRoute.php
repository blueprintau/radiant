<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: the composite-PK INTERMEDIATE for the through chain — a route
 * identified by (region_id, country), the same tuple as its owning region.
 * The through relation's second key pairs the leg's FK against THIS
 * model's composite PK.
 */
class CmpRoute extends Model
{
    /**
     * The owning region's id — the FIRST composite-PK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $region_id;

    /**
     * The owning region's country — the SECOND composite-PK column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2, primaryKey: true)]
    public string $country;

    /**
     * The route's label.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;
}
