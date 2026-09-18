<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Attributes\Table;

/**
 * A rule-3 variant that is LEGAL: a subclass of an ABSTRACT base that
 * declares an explicit #[Table] — the base owns no table, so this class IS
 * the first table owner in its chain.
 */
#[Table(name: 'contractors')]
class Contractor extends AbstractPerson
{
    /**
     * A column the abstract base does not have.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;
}
