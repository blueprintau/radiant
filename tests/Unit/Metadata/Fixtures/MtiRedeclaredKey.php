<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * An MTI mis-declaration: the child redeclares the primary-key column the
 * factory derives from the parent — the shared PK IS the table link.
 */
#[Table(name: 'bad_admins')]
class MtiRedeclaredKey extends User
{
    /**
     * Redeclaring the derived key is a build error.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A column that belongs on the child's own table.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}
