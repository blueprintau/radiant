<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * A third-level MTI child: MtiAdmin's table (admins) is its parent table,
 * users is the grandparent table. Proves the chain walks to any depth.
 */
#[Table(name: 'super_admins')]
class MtiSuperAdmin extends MtiAdmin
{
    /**
     * A column that belongs only on the deepest table.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 128)]
    public string $scope;
}
