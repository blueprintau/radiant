<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * An abstract intermediate holding shared columns (rule 4 merge source).
 */
abstract class AbstractPerson extends Model
{
    /**
     * The shared primary key.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A shared column, merged into the descendant's table.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $name;
}
