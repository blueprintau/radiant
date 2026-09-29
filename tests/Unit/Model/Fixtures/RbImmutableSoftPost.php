<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * Fixture: a soft-deletable model whose delete column is DECLARED as
 * CarbonImmutable — the re-base branch of writeDeletedAtColumn().
 */
class RbImmutableSoftPost extends Model
{
    use SoftDeletes;

    /**
     * The renamed soft-delete column.
     *
     * @return string The column name.
     */
    public static function deletedAtColumn(): string
    {
        return 'removed_at';
    }

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The soft-delete timestamp — declared IMMUTABLE.
     *
     * @var \Carbon\CarbonImmutable|null
     */
    #[Column(type: ColumnType::DateTime, name: 'removed_at', nullable: true)]
    public ?\Carbon\CarbonImmutable $removed_at;
}
