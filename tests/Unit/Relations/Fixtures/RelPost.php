<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;

/**
 * Fixture: the child — belongsTo + hasMany target.
 */
class RelPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The author's id (the FK; stored as author_id).
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'author_id')]
    public ?int $authorId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * A groupable status label (the countBy/aggregateBy fixture column).
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 16, nullable: true)]
    public ?string $status;

    /**
     * A summable counter (the aggregateBy decode fixture column).
     *
     * @var int|null
     */
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $views;

    /**
     * The post's author (inverse).
     *
     * @return BelongsTo<RelUser>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'author_id');
    }
}
