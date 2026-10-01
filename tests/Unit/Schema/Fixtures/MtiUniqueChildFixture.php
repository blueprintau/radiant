<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * Fixture: an MTI child whose class-level index covers an inherited
 * column — the constraint belongs to the parent table.
 */
#[Table(name: 'mti_unique_child_fixtures')]
#[Index(columns: ['email'])]
class MtiUniqueChildFixture extends MtiParentFixture
{
    /**
     * A child-only column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;
}
