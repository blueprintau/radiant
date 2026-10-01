<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a trait whose #[ModelScope] method returns a
 * non-array — the scope must return a list of ScopeCondition instances.
 */
trait ModelScopeBadReturnTrait
{
    /**
     * A scope method returning a string — triggers the build error.
     *
     * @return string
     */
    #[ModelScope]
    public static function activeBadReturn(): string
    {
        return 'not-an-array';
    }
}

/**
 * The consuming model for {@see ModelScopeBadReturnTrait}.
 */
class ModelScopeBadReturnModel extends Model
{
    use ModelScopeBadReturnTrait;

    /**
     * A status column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;
}
