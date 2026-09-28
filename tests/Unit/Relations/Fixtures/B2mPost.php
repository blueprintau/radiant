<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsToMany;

/**
 * A many-to-many parent — posts link to tags through posts_tags.
 */
class B2mPost extends Model
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
     * The many-to-many relation.
     *
     * @return BelongsToMany<B2mTag>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(B2mTag::class);
    }
}
