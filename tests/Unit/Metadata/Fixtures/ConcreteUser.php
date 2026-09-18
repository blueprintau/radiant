<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * The first concrete descendant of AbstractPerson — merges its columns and
 * resolves its table by the root convention (rule 4 + rule 5).
 */
class ConcreteUser extends AbstractPerson
{
    /**
     * A column the abstract base does not have — legal (the base owns no
     * table, so this class IS the table owner).
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;
}
