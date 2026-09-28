<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Concerns\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;

/**
 * Fixture: filter-vocabulary parent.
 */
class FvUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The user's age (0 = unknown).
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $age;

    /**
     * The user's posts.
     *
     * @return HasMany<FvPost>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(FvPost::class, 'user_id');
    }
}
