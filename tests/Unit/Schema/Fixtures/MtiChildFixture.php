<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * Fixture: an MTI child — owns one column of its own.
 */
#[Table(name: 'mti_child_fixtures')]
class MtiChildFixture extends MtiParentFixture
{
    /**
     * A child-only column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;
}
