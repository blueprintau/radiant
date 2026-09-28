<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphMany;
use BlueprintAU\Radiant\Relations\MorphOne;

/**
 * A polymorphic PARENT — posts own comments of any class.
 */
class PolyPost extends Model
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
    public string $title;

    /**
     * The polymorphic children.
     *
     * @return MorphMany<PolyComment>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(PolyComment::class, 'commentable');
    }

    /**
     * The polymorphic single child.
     *
     * @return MorphOne<PolyImage>
     */
    public function image(): MorphOne
    {
        return $this->morphOne(PolyImage::class, 'imageable');
    }
}
