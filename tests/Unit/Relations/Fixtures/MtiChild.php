<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * Fixture: the MTI child — own table, derived shared key.
 */
#[Table(name: 'mti_admins')]
class MtiChild extends MtiUser
{
    /**
     * The admin level (on the child's own table).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
