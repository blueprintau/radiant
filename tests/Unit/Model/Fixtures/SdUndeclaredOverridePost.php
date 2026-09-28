<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * Fixture: a SoftDeletes model whose deletedAtColumn() override names a
 * column with NO matching #[Column] declaration — the fail-fast metadata
 * error (an override is an explicit claim that the column is declared).
 */
class SdUndeclaredOverridePost extends Model
{
    use SoftDeletes;

    /**
     * Names a column that is never declared — the build error.
     *
     * @return string The column name.
     */
    public static function deletedAtColumn(): ?string
    {
        return 'missing_at';
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
}
