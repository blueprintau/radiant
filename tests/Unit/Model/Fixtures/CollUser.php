<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;

/**
 * Fixture: user with a posts relation.
 */
class CollUser extends Model
{
    /**
     * The user's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's email.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;

    /**
     * The user's posts.
     *
     * @return HasMany<CollPost>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(CollPost::class, 'user_id');
    }
}
