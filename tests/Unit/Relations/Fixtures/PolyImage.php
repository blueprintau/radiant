<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphTo;

/**
 * A second child class for the morphOne side.
 */
#[Morphs(name: 'imageable', nullable: true)]
class PolyImage extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $path;

    /**
     * The inverse polymorphic relation — the dynamic form: no allowlist,
     * so any model class can resolve (the honest Model bound).
     *
     * @return MorphTo<Model> The relation.
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo('imageable');
    }

    /**
     * An allowlisted variant of the relation — exercises the $types gate.
     * The caller-chosen list means the template stays the Model bound.
     *
     * @param list<class-string<Model>> $types The allowed morph aliases.
     * @return MorphTo<Model> The constrained relation.
     */
    public function allowlistedWith(array $types): MorphTo
    {
        return $this->morphTo('imageable', null, null, null, $types);
    }
}
