<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphToMany;

/**
 * A SECOND polymorphic many-to-many parent class — same pivot, same pool.
 */
class MtmVideo extends Model
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
     * The polymorphic many-to-many relation.
     *
     * @return MorphToMany<MtmTag>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(MtmTag::class, 'taggable');
    }
}
