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

    /**
     * The pivot table named by a MODEL class-string.
     *
     * @return BelongsToMany<B2mTag>
     */
    public function tagged(): BelongsToMany
    {
        return $this->belongsToMany(B2mTag::class, table: PivotClassBtm::class);
    }

    /**
     * A guarded form for construction-failure tests.
     *
     * @param  string  $table
     * @return BelongsToMany<B2mTag>
     */
    public function belongsToManyRaw(string $table): BelongsToMany
    {
        return $this->belongsToMany(B2mTag::class, table: $table);
    }

    /**
     * A pivot CLASS whose table collides with the endpoint's.
     *
     * @param  class-string<Model>  $pivot
     * @return BelongsToMany<B2mTag>
     */
    public function tagThrough(string $pivot): BelongsToMany
    {
        return $this->belongsToMany(B2mTag::class, table: $pivot);
    }
}
