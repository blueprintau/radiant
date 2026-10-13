<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\MassPrunable;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

/**
 * Fixture: a MassPrunable MTI child — pruneAll() must fail fast (a bulk
 * DELETE would orphan the ancestor rows).
 */
#[Table(name: 'mp_mti_children')]
class MpMtiChild extends MtiUser
{
    use SoftDeletes;
    use MassPrunable;

    /**
     * The child's own column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;

    /**
     * The retention predicate (irrelevant — the MTI guard fires first).
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery();
    }
}
