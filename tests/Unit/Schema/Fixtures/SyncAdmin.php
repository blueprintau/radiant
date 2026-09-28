<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * An MTI child of SyncUser — exercises the derived-key + emitted-FK path.
 */
#[Table(name: 'sync_admins')]
class SyncAdmin extends SyncUser
{
    /**
     * A column that belongs on the child's own table.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
