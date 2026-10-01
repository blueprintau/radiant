<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The consuming model for {@see ModelScopeBadElementTrait}.
 */
class ModelScopeBadElementModel extends Model
{
    use ModelScopeBadElementTrait;

    /**
     * A status column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;
}
