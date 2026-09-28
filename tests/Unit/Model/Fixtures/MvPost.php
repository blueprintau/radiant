<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: the related side of the CamelCase FK-convention pair — its
 * table carries the derived `mv_author_id` column.
 */
class MvPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The derived convention FK — `MvAuthor` + `_id`. The property is
     * camelCase per the convention's output (`mv_author_id` maps to no
     * auto-snake rule — properties ARE column names unless `name:` says
     * otherwise), so `name:` pins the DB name explicitly.
     *
     * @var int|null
     */
    #[Column(name: 'mv_author_id', type: ColumnType::BigInt, nullable: true)]
    public ?int $mvAuthorId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}
