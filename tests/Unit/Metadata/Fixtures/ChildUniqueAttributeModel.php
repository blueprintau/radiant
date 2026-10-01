<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * A metadata error fixture: a child REDECLARING the ancestor's unique
 * column WITHOUT the flag, plus a class-level #[Unique] — the
 * redeclaration hides the ancestor's mapping, so the ancestor-chain
 * branch of the duplicate check fires.
 */
#[Table(name: 'child_unique_attribute_models')]
#[Unique(columns: ['email'])]
class ChildUniqueAttributeModel extends AncestorUniqueFlagModel
{
    /**
     * The redeclared column — no unique flag here.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;
}
