<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * The canonical MTI child: inherits User's columns (stored in `users`) and
 * adds its own level column (stored in `admins`). The shared `id` is
 * derived — the child declares no key of its own.
 */
#[Table(name: 'admins')]
class MtiAdmin extends User
{
    /**
     * A column that belongs on the child's own table.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
