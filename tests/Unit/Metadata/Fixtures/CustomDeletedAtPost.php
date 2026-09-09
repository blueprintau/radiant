<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * A model using SoftDeletes that DECLARES its own delete column (renamed +
 * hidden) — the user declaration wins over the synthetic one.
 */
class CustomDeletedAtPost extends Model
{
    use SoftDeletes;

    /**
     * The custom soft-delete column name.
     *
     * @return string The column name.
     */
    public static function deletedAtColumn(): string
    {
        return 'removed_at';
    }

    /**
     * The primary key.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user-declared (renamed, hidden) soft-delete column. The DB name
     * must match {@see deletedAtColumn()} for the declaration to be found.
      *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: ColumnType::DateTime, nullable: true, name: 'removed_at')]
    public ?\Carbon\Carbon $removedAt;
}
