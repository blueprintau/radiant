<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ScopeCondition;

/**
 * A metadata error fixture: a trait whose #[ModelScope] declares a column
 * that does not exist on the consuming model.
 */
trait ModelScopeUnknownColumnTrait
{
    /**
     * A scope over a column the consuming model lacks — triggers the
     * build error.
     *
     * @return list<ScopeCondition>
     */
    #[ModelScope]
    public static function activeUnknownColumn(): array
    {
        return [new ScopeCondition('ghost_column', WhereOperator::Eq, 'active')];
    }
}
