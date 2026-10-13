<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;

/**
 * Fixture: a plain Prunable model — no soft deletes. prunable() is driven
 * by a static expiry cutoff the tests rewrite between runs.
 */
class PrPost extends Model
{
    use Prunable;

    /**
     * The post's id.
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
     * The expiry instant the fixture's prunable() compares against.
     *
     * @var \Carbon\Carbon
     */
    public static \Carbon\Carbon $expiry;

    /**
     * Rows pruned via prune() — observed by the per-row tests.
     *
     * @var list<string>
     */
    public static array $prunedTitles = [];

    /**
     * Reset the per-test static state.
     *
     * @return void
     */
    public static function resetPrunableFixtureState(): void
    {
        self::$prunedTitles = [];
    }

    /**
     * The retention predicate: rows expired before the static cutoff.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery()->where('created_at', '<', self::$expiry);
    }

    /**
     * The row's creation instant — the expiry column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, name: 'created_at')]
    public \Carbon\Carbon $createdAt;
}
