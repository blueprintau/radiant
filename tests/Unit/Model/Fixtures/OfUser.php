<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: orFail parent model.
 */
class OfUser extends Model
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
     * The user's posts (one-to-many).
     *
     * @return \BlueprintAU\Radiant\Relations\HasMany<OfPost>
     */
    public function posts(): \BlueprintAU\Radiant\Relations\HasMany
    {
        return $this->hasMany(OfPost::class, 'author_id');
    }

    /**
     * A one-to-one relation — the "must exist" orFail probe.
     *
     * @return \BlueprintAU\Radiant\Relations\HasOne<OfPost>
     */
    public function featuredPost(): \BlueprintAU\Radiant\Relations\HasOne
    {
        return $this->hasOne(OfPost::class, 'author_id');
    }

    /**
     * A NON-public relation — the resolveRelation visibility probe.
     *
     * @return \BlueprintAU\Radiant\Relations\HasMany<OfPost>
     */
    protected function hiddenPosts(): \BlueprintAU\Radiant\Relations\HasMany
    {
        return $this->hasMany(OfPost::class, 'author_id');
    }

    /**
     * A public method that does NOT return a Relation — the
     * resolveRelation return-type probe. Returns a literal so it is
     * safe to invoke on a constructor-less prototype.
     *
     * @return string
     */
    public function name(): string
    {
        return 'probe';
    }
}
