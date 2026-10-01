<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * An inner trait declaring a scope — found through the outer trait's
 * trait-uses-trait nesting.
 */
trait InnerScopeTrait
{
    /**
     * The inner scope — found through the outer trait.
     *
     * @return list<ScopeCondition>
     */
    #[ModelScope]
    public static function innerActive(): array
    {
        return [new ScopeCondition('status', WhereOperator::Eq, 'active')];
    }
}
