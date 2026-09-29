<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;

/**
 * Fixture: a stamped model whose stamp columns are DECLARED as
 * CarbonImmutable — the re-base branch of writeTimestampColumn().
 */
class RbImmutableStampedPost extends Model
{
    use Timestamps;

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
     * The created-at stamp — declared IMMUTABLE.
     *
     * @var \Carbon\CarbonImmutable|null
     */
    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?\Carbon\CarbonImmutable $began_at;

    /**
     * The updated-at stamp — declared IMMUTABLE.
     *
     * @var \Carbon\CarbonImmutable|null
     */
    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?\Carbon\CarbonImmutable $modified_at;

    /**
     * The renamed created-at column.
     *
     * @return string
     */
    public static function createdAtColumn(): string
    {
        return 'began_at';
    }

    /**
     * The renamed updated-at column.
     *
     * @return string
     */
    public static function updatedAtColumn(): string
    {
        return 'modified_at';
    }
}
