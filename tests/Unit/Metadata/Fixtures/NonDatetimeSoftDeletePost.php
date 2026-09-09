<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * A rule violation: a SoftDeletes model that declares the delete column
 * with a non-datetime type — the fail-fast metadata error.
 */
class NonDatetimeSoftDeletePost extends Model
{
    use SoftDeletes;

    /**
     * The primary key.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * WRONG type for a soft-delete column — triggers the build error. The
     * DB name must match {@see SoftDeletes::deletedAtColumn()} for the
     * declaration to be found.
      *
     * @var int
     */
    #[Column(type: ColumnType::Int, name: 'deleted_at')]
    public int $deletedAt;
}
