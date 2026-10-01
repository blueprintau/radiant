<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * A metadata error fixture: a trait whose #[ModelScope] returns an array
 * whose element is not a ScopeCondition.
 */
trait ModelScopeBadElementTrait
{
    /**
     * A scope method returning a non-ScopeCondition element — triggers
     * the build error.
     *
     * @return list<mixed>
     */
    #[ModelScope]
    public static function activeBadElement(): array
    {
        return ['not-a-condition'];
    }
}
