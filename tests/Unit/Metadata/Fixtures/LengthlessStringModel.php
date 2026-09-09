<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a string column without a length.
 */
class LengthlessStringModel extends Model
{
    /**
     * A string column missing its required length — triggers the build error.
      *
     * @var string
     */
    #[Column(type: ColumnType::String)]
    public string $name;
}
