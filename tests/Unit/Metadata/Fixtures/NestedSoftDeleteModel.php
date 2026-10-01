<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The consuming model for {@see OuterSoftDeleteTrait} — the synthetic
 * deleted_at column must be injected through the trait nesting.
 */
class NestedSoftDeleteModel extends Model
{
    use OuterSoftDeleteTrait;

    /**
     * A name column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $name;
}
