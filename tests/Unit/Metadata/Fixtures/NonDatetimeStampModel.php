<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;

/**
 * A metadata error fixture: a model declaring its stamp column as a
 * non-datetime type.
 */
class NonDatetimeStampModel extends Model
{
    use Timestamps;

    /**
     * A name column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $name;

    /**
     * The created-at stamp declared as an int — triggers the build error.
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, name: 'created_at')]
    public int $createdAt;
}
