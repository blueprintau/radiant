<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a model with declared column defaults — the create-family
 * default-materialization probe.
 */
class FcDefaulted extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A defaulted int column (unset at create — the default materializes).
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, default: 0)]
    public int $hits;

    /**
     * A defaulted string column (unset at create — the default materializes).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32, default: 'anon')]
    public string $author;

    /**
     * A nullable column with no declared default (stays uninitialized).
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 64, nullable: true)]
    public ?string $note;
}
