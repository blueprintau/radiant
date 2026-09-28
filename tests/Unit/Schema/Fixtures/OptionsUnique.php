<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model whose #[Unique] carries OPTIONS — nullsNotDistinct (the classic
 * "one active row per user" constraint) and a partial predicate on the
 * #[Index]. Both flow through fromMetadata() into the index shapes.
 */
#[Unique(columns: ['userId'], nullsNotDistinct: true, name: 'sync_options_active_unique')]
#[Index(columns: ['country'], where: 'regionId IS NOT NULL', name: 'sync_options_country_index')]
#[Table(name: 'sync_options')]
class OptionsUnique extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user column — nullable so NULLS NOT DISTINCT has meaning.
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true)]
    public int|null $userId;

    /**
     * The country column — carries the partial index.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;

    /**
     * The region column — referenced by the partial predicate.
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true)]
    public int|null $regionId;
}
