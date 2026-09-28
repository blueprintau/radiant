<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * Fixture: soft-deletable post with a RENAMED delete column.
 */
class SdRenamedPost extends Model
{
    use SoftDeletes;

    /**
     * The renamed soft-delete column.
     *
     * @return string The column name.
     */
    public static function deletedAtColumn(): string
    {
        return 'renamed_at';
    }

    /**
     * The post's id.
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
     * The renamed soft-delete timestamp (user-declared, name matches).
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: ColumnType::DateTime, name: 'renamed_at', nullable: true)]
    public ?\Carbon\Carbon $renamedAt;
}
