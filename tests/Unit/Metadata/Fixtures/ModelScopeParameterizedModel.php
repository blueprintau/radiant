<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * A metadata error fixture: a trait whose #[ModelScope] method takes a
 * parameter — the scope must be static and zero-parameter.
 */
trait ModelScopeParameterizedTrait
{
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

/**
 * The consuming model for {@see ModelScopeParameterizedTrait}.
 */
class ModelScopeParameterizedModel extends Model
{
    use ModelScopeParameterizedTrait;

    /**
     * A status column.
     *
     * @var string
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::String, length: 32)]
    public string $status;
}
