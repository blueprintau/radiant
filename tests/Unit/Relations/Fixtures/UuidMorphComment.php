<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphTo;

/**
 * A model with a UUID-keyed `#[Morphs]` pair — the keyType path.
 */
#[Morphs(name: 'commentable', keyType: ColumnType::Uuid)]
class UuidMorphComment extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $body;

    /**
     * The inverse polymorphic relation.
     *
     * @return MorphTo<Model>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable');
    }
}
