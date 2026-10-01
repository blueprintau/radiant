<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * A metadata error fixture: an MTI child extending a composite-PK root —
 * multi-table inheritance requires a single named root key.
 */
#[Table(name: 'mti_composite_children')]
class MtiChildOfCompositePk extends CompositePkRootModel
{
    /**
     * A child-only column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $label;
}
