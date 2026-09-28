<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;

/**
 * Fixture: an intermediate table for through relations.
 */
class RelTeam extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The team owner's user id (stored as owner_id).
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'owner_id')]
    public int $ownerId;

    /**
     * The team name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The team's owner (inverse belongsTo) — the third level of the
     * teamPosts nesting chain.
     *
     * @return BelongsTo<RelUser>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'owner_id');
    }
}
