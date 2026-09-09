<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Unique;

/**
 * A metadata error fixture: a #[Unique] naming a column that does not
 * exist on the model (the renamed-property fail-fast).
 */
#[Unique(columns: ['emial'])]
class UnknownConstraintColumnModel extends Model
{
    /**
     * The column the constraint misspells.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;
}
