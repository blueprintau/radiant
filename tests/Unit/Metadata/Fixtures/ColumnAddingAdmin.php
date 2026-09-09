<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A rule-2 violation: a concrete subclass of a table-owning model that
 * adds columns of its own without declaring #[Table] — the columns have
 * nowhere to go, so this is the rule-2 build error.
 */
class ColumnAddingAdmin extends User
{
    /**
     * A column the parent does not have — triggers the build error.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
