<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a table-owning model referenced by class-string.
 */
#[Table(name: 'ref_roles')]
class RefRole extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The role's label.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $label;
}
