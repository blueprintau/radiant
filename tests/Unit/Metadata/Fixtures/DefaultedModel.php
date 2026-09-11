<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model with declared column defaults — used to prove post-insert
 * materialization (uninitialized properties pick up their column's
 * default after save()).
 */
class DefaultedModel extends Model
{
    /**
     * No PHP default — uninitialized at `new`; the DB default applies on
     * INSERT and must be materialized onto the property after save().
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, default: 0)]
    public int $hits;

    /**
     * A nullable column with a declared default.
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 32, nullable: true, default: 'anon')]
    public ?string $author;

    /**
     * No declared column default — stays uninitialized after save().
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 255, nullable: true)]
    public ?string $note;
}
