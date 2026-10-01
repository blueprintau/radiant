<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

/**
 * Fixture: the MTI child with SoftDeletes — the trait's scope column is
 * SYNTHETIC (no PHP property), so the scope's partition qualification
 * runs on a partitioned builder.
 */
#[Table(name: 'mti_soft_children')]
class MtiSoftChild extends MtiUser
{
    use SoftDeletes;

    /**
     * The admin level (on the child's own table).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
