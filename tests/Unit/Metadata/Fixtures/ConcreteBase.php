<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Model;

/**
 * A concrete organizational base — no columns of its own (rule 4).
 */
class ConcreteBase extends Model
{
    /**
     * A helper property, deliberately not a column.
     *
     * @var string
     */
    public string $helper = '';
}
