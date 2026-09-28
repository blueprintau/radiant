<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model with TWO class-level uniques — proves unique index names are
 * derived per constraint (a hardcoded name would emit two CREATE UNIQUE
 * INDEX statements with the same name; the second fails at the DB).
 */
#[Unique(columns: ['regionId', 'country'])]
#[Unique(columns: ['country', 'title'])]
#[Table(name: 'sync_multi_unique')]
class MultiUnique extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region column — part of the first unique.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    /**
     * The country column — part of both uniques.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;

    /**
     * The title column — part of the second unique.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}
