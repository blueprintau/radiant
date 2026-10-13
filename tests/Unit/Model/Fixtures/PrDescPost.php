<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;

/**
 * Fixture: a Prunable model whose prunable() orders the primary key
 * DESC — the query-contract guard must reject it at pruneAll() entry
 * (the keyset cursor would silently under-prune).
 */
class PrDescPost extends Model
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
     * The row's creation instant — the expiry column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, name: 'created_at')]
    public \Carbon\Carbon $createdAt;

    /**
     * The retention predicate ordering the PK descending — the guard's
     * failure shape.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery()->orderBy('id', 'desc');
    }
}
