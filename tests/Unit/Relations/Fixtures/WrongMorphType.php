<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a declared type column with the WRONG type.
 */
#[Morphs(name: 'wrongable')]
#[Table(name: 'morph_wrong_type')]
class WrongMorphType extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * An int column where the morph TYPE column must be — a build error.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'wrongable_type')]
    public int $wrongableType;
}
