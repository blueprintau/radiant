<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: #[Table(name: '')] WITH own columns — the
 * empty-name mis-declaration that survives the columnless guard.
 */
#[Table(name: '')]
class EmptyNamedTableWithColumns extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A name column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $name;
}