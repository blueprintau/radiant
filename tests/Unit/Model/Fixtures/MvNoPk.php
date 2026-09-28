<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a model with columns but NO primary key at all.
 */
class MvNoPk extends Model
{
    /**
     * A plain column — no PK declared anywhere on the class.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $name;
}
