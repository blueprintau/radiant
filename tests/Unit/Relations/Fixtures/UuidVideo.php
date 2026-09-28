<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphMany;

/**
 * A UUID-primary-key model — the morph target for the keyType path.
 */
class UuidVideo extends Model
{
    /**
     * The primary key.
     *
     * @var string
     */
    #[Column(type: ColumnType::Uuid, primaryKey: true)]
    public string $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;

    /**
     * A bigint-keyed morph pair pointing at THIS model — the forward-side
     * PK-type-mismatch test's entry point.
     *
     * @return MorphMany<PolyComment>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(PolyComment::class, 'commentable');
    }
}
