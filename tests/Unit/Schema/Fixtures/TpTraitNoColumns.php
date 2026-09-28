<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;

/**
 * A stamped model with NO declared stamp columns — the trait auto-declares
 * them as synthetic NOT NULL datetime mappings.
 */
#[Table('tp_auto')]
final class TpTraitNoColumns extends Model
{
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;
}
