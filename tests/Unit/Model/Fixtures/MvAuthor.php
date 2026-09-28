<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;

/**
 * Fixture: CamelCase model for FK-convention derivation. The short class
 * name `MvAuthor` must derive the foreign key `mv_author_id`.
 */
class MvAuthor extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The author's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The author's posts — the related table declares the derived
     * `mv_author_id` column, so the convention-driven factory compiles.
     *
     * @return HasMany<MvPost>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(MvPost::class);
    }
}
