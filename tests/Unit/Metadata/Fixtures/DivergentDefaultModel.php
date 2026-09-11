<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: the PHP property default diverges from the
 * declared column default.
 */
class DivergentDefaultModel extends Model
{
    /**
     * A PHP default that disagrees with the column default — triggers the
     * build error (the property is always initialized after `new`, so its
     * value shadows the column default on every model INSERT).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64, default: 'anon')]
    public string $name = 'someone';
}
