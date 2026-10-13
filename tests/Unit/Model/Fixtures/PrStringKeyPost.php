<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;

/**
 * Fixture: a Prunable model whose PRIMARY KEY is a string holding an ISO
 * datetime — locks the claim that the keyset cursor's codec-bound
 * comparison is order-preserving across string-keyed rows (a keyset hop
 * must not skip or revisit rows, whatever the key's domain).
 *
 * (The schema layer rejects a datetime column AS the primary key —
 * primaryKeyCapable() — so the datetime domain rides a string key.)
 */
#[Table(name: 'pr_string_key_posts')]
class PrStringKeyPost extends Model
{
    use Prunable;

    /**
     * The ISO-datetime string PRIMARY KEY — the keyset cursor binds
     * decoded string values through the codec.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32, primaryKey: true)]
    public string $observedAt;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The row's creation instant — the expiry column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, name: 'created_at')]
    public \Carbon\Carbon $createdAt;

    /**
     * The expiry instant the fixture's prunable() compares against.
     *
     * @var \Carbon\Carbon
     */
    public static \Carbon\Carbon $expiry;

    /**
     * The retention predicate: rows expired before the static cutoff.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery()->where('created_at', '<', self::$expiry);
    }
}
