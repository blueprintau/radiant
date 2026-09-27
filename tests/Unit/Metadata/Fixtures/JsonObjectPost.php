<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Model;

/**
 * A model exercising JsonSerializable object property casting.
 */
#[Table(name: 'json_object_posts')]
class JsonObjectPost extends Model
{
    /**
     * The primary key.
     *
     * @var int|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int|null $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::String, length: 100)]
    public string $title;

    /**
     * The preferences object — the JsonSerializable cast probe column.
     *
     * @var UserPreferences|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Json, nullable: true)]
    public UserPreferences|null $preferences;
}
