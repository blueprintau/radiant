<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a morph child whose morph key column is a string — mismatching
 * PolyPost's bigint primary key, so the type guard fires.
 */
class StringKeyMorphTarget extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The morph FK column — a STRING, mismatching the parent's bigint PK.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 36)]
    public string $commentable_id;

    /**
     * The morph type column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $commentable_type;
}
