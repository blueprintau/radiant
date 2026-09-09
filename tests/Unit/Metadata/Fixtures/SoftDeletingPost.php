<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * A model using SoftDeletes with no declared delete column — the factory
 * injects the synthetic datetime mapping.
 */
class SoftDeletingPost extends Model
{
    use SoftDeletes;

    /**
     * The primary key.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain string column.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;
}
