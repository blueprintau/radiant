<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Unique;

/**
 * A duplicate-declaration violation: `#[Column(unique: true)]` on a column
 * AND a class-level `#[Unique]` over that same single column.
 */
#[Unique(columns: ['email'])]
class DoubleUniqueModel extends Model
{
    /**
     * A unique-flag column, also covered by the class-level #[Unique] —
     * triggers the duplicate-declaration build error.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;
}
