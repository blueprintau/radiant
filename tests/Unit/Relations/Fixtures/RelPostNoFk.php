<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;

/**
 * Fixture: a post that maps the rel_posts table WITHOUT declaring the
 * author FK column — the convention-derived belongsTo cannot resolve.
 *
 * The point is the DEFAULT-KEY path: `belongsTo(RelUser::class)` with no
 * explicit keys derives `rel_user_id` from the related class's short name
 * (Model::defaultForeignKeyFromKey → defaultForeignKeyFrom), then fails
 * the assertColumnExists guard because this model never declares it.
 */
#[Table(name: 'rel_posts')]
class RelPostNoFk extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The convention-derived belongsTo — NO explicit keys, so the
     * factory walks the default-key path.
     *
     * @return BelongsTo<RelUser>
     */
    public function authorByConvention(): BelongsTo
    {
        return $this->belongsTo(RelUser::class);
    }
}
