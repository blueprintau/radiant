<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;

/**
 * Fixture: the through target — a post that belongs to a team.
 */
class RelTeamPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The team's id (stored as team_id).
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'team_id')]
    public int $teamId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The post's team.
     *
     * @return BelongsTo<RelTeam>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(RelTeam::class, 'team_id');
    }
}
