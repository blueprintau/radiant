<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a declared type column that is TOO SHORT for
 * a full class-string.
 */
#[Morphs(name: 'shortable')]
#[Table(name: 'morph_short_type')]
class ShortMorphType extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A string column too short for a class-string — a build error.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 8, name: 'shortable_type')]
    public string $shortableType;
}
