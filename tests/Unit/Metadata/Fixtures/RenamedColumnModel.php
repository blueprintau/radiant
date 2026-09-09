<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model whose DB column names differ from the property names — the
 * columnName ≠ propertyName path (save / hydrate / dirty tracking must
 * stay column-keyed end to end).
 */
#[\BlueprintAU\Radiant\Attributes\Table(name: 'renamed_columns')]
class RenamedColumnModel extends Model
{
    /**
     * The primary key — property `id`, DB column `pk`.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true, name: 'pk')]
    public int $id;

    /**
     * A renamed column — property `status`, DB column `state`.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 16, name: 'state')]
    public string $status;

    /**
     * Another renamed column with a Carbon cast — property `when`, DB
     * column `occurred_at`.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: ColumnType::DateTime, nullable: true, name: 'occurred_at')]
    public ?\Carbon\Carbon $when;
}
