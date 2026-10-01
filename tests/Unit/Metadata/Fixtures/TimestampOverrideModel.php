<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;

/**
 * A metadata error fixture: a model overriding createdAtColumn() to a
 * column name it never declares.
 */
class TimestampOverrideModel extends Model
{
    use Timestamps;

    /**
     * Override the created-at column to an undeclared name — triggers the
     * build error.
     *
     * @return string|null
     */
    public static function createdAtColumn(): ?string
    {
        return 'created';
    }

    /**
     * A name column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $name;
}
