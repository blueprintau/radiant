<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * Metadata error fixture: a model whose CLASS BODY declares a
 * #[ModelScope] method with a parameter — the static zero-param contract
 * must cover body declarations.
 */
class BodyScopeParameterizedModel extends Model
{
    /**
     * A status column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * A scope method with a parameter — triggers the build error.
     *
     * @param  string  $unused  The unused parameter.
     * @return list<ScopeCondition>
     */
    #[ModelScope]
    public static function activeWithParam(string $unused): array
    {
        unset($unused);

        return [new ScopeCondition('status', WhereOperator::Eq, 'active')];
    }
}
