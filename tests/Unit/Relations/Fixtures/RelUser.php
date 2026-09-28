<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;
use BlueprintAU\Radiant\Relations\HasOne;
use BlueprintAU\Radiant\Relations\HasOneThrough;
use BlueprintAU\Radiant\Relations\HasManyThrough;

/**
 * Fixture: the parent — one-to-many owner.
 */
class RelUser extends Model
{
    /**
     * The auto-increment primary key.
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
     * The user's posts (one-to-many).
     *
     * @return HasMany<RelPost>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(RelPost::class, 'author_id');
    }

    /**
     * A relation declaring a nonexistent FK — the fail-fast cross-check
     * fixture.
     *
     * @return HasMany<RelPost>
     */
    public function brokenPosts(): HasMany
    {
        return $this->hasMany(RelPost::class, 'bogus_id');
    }

    /**
     * The user's newest post (one-to-one).
     *
     * @return HasOne<RelPost>
     */
    public function featuredPost(): HasOne
    {
        return $this->hasOne(RelPost::class, 'author_id');
    }

    /**
     * A non-relation method referenced in with() — the fail-fast fixture.
     *
     * @return string
     */
    public function notARelation(): string
    {
        return 'nope';
    }

    /**
     * The user's team posts (two-hop through the team).
     *
     * @return HasManyThrough<RelTeamPost>
     */
    public function teamPosts(): HasManyThrough
    {
        return $this->hasManyThrough(RelTeamPost::class, RelTeam::class, 'owner_id', 'team_id');
    }

    /**
     * The user's first team post (one-to-one through the team).
     *
     * @return HasOneThrough<RelTeamPost>
     */
    public function featuredTeamPost(): HasOneThrough
    {
        return $this->hasOneThrough(RelTeamPost::class, RelTeam::class, 'owner_id', 'team_id');
    }
}
