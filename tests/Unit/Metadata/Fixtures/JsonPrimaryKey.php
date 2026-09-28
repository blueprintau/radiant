<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model declaring a non-PK-capable column as its primary key — the
 * primaryKey-capability fail-fast path.
 */
class JsonPrimaryKey extends Model
{
    /**
     * The primary key — a JSON column, which cannot hold a key value.
     *
     * @var array<string, mixed>
     */
    #[Column(type: ColumnType::Json, primaryKey: true)]
    public array $id;
}
