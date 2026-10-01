<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A parent model declaring a unique-flag column — the ancestor side of
 * the cross-inheritance duplicate-flag throw.
 */
class AncestorUniqueFlagModel extends Model
{
    /**
     * A unique-flag column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;
}
