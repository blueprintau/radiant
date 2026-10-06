<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphToMany;

/**
 * The shared related model — and the INVERSE direction's parent.
 */
class MtmTag extends Model
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
    public string $label;

    /**
     * The INVERSE polymorphic many-to-many relation — every post tagged
     * with this tag.
     *
     * @return MorphToMany<MtmPost, Model>
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(MtmPost::class, 'taggable');
    }

    /**
     * The inverse direction with a pool allowlist — the cross-type read.
     *
     * @return MorphToMany<MtmPost, MtmPost|MtmVideo>
     */
    public function poolPosts(): MorphToMany
    {
        return $this->morphedByMany(MtmPost::class, 'taggable', poolTypes: [MtmPost::class, MtmVideo::class]);
    }

    /**
     * The direct direction — pool() must refuse it.
     *
     * @return MorphToMany<MtmPost, MtmPost|MtmVideo>
     */
    public function directPool(): MorphToMany
    {
        return $this->morphToMany(MtmPost::class, 'taggable', poolTypes: [MtmPost::class, MtmVideo::class]);
    }
}
