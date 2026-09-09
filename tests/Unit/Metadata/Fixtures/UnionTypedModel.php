<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a union-typed column property.
 */
class UnionTypedModel extends Model
{
    /**
     * A union-typed column property — triggers the build error.
     *
     * @var int|string
     */
    #[Column(type: ColumnType::String, length: 16)]
    public int|string $status;
}
