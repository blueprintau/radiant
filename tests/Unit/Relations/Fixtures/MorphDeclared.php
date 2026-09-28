<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model declaring the morph columns ITSELF — the user-declaration-wins
 * precedence path.
 */
#[Morphs(name: 'taggable')]
#[Table(name: 'morph_declared')]
class MorphDeclared extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user-declared type column — wins over the synthetic injection.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, name: 'taggable_type')]
    public string $taggableType;

    /**
     * The user-declared key column — wins over the synthetic injection.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'taggable_id')]
    public int $taggableId;
}
