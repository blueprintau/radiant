<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * Fixture: a model declaring a #[ModelScope] in its own CLASS BODY —
 * the scope applies to queries and strips via withoutScope(model class).
 */
class BodyScopePost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row's publication status.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * The body-declared scope — only published rows.
     *
     * @return list<ScopeCondition>
     */
    #[ModelScope]
    protected static function onlyPublished(): array
    {
        return [new ScopeCondition('status', WhereOperator::Eq, 'published')];
    }
}
