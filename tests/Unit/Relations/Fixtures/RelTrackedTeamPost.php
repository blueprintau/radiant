<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * Fixture: a soft-deleting through target — same shape as RelTeamPost but
 * with the SoftDeletes trait, so the through eager-load path can be tested
 * against a leading trait scope.
 */
class RelTrackedTeamPost extends Model
{
    use SoftDeletes;

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
}
