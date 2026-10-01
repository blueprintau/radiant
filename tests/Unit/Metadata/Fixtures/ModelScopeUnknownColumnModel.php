<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The consuming model for {@see ModelScopeUnknownColumnTrait}.
 */
class ModelScopeUnknownColumnModel extends Model
{
    use ModelScopeUnknownColumnTrait;

    /**
     * A status column — the scope's `ghost_column` does not exist.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;
}
