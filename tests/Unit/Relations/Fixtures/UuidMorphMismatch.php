<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model whose declared key column contradicts the attribute's keyType —
 * the declared-column-wins fail-fast path for keyType.
 */
#[Morphs(name: 'taggable', keyType: ColumnType::Uuid)]
#[Table(name: 'uuid_morph_mismatch')]
class UuidMorphMismatch extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The declared key column — bigint, but the attribute demands uuid.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'taggable_id')]
    public int $taggableId;
}
