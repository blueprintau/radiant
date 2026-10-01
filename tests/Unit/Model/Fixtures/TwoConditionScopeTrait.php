<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * A trait declaring a TWO-condition scope — the second condition joins
 * the first with an explicit boolean, exercising the group's internal
 * join arm.
 */
trait TwoConditionScopeTrait
{
    /**
     * The two-condition scope: published OR archived.
     *
     * @return list<ScopeCondition>
     */
    #[ModelScope]
    public static function visibleStates(): array
    {
        return [
            new ScopeCondition('status', WhereOperator::Eq, 'published'),
            new ScopeCondition('status', WhereOperator::Eq, 'archived', WhereBoolean::Or),
        ];
    }
}
