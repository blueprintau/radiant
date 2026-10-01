<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;
use BlueprintAU\Radiant\Timestamps;

/**
 * Fixture: the MTI child with Timestamps — the stamp columns are
 * SYNTHETIC (no PHP property), so the insert's property-less branch runs.
 */
#[Table(name: 'mti_stamped_children')]
class MtiStampedChild extends MtiUser
{
    use Timestamps;

    /**
     * The admin level (on the child's own table).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
