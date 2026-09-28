<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\MorphTo;

/**
 * The polymorphic CHILD — its (type, key) pair points at any parent.
 */
#[Morphs(name: 'commentable', nullable: true)]
class PolyComment extends Model
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
    public string $body;

    /**
     * The inverse polymorphic relation — the dynamic form: no allowlist,
     * so any model class can resolve (the honest Model bound).
     *
     * @return MorphTo<Model> The relation.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable');
    }

    /**
     * An allowlisted variant of the relation — exercises the $types gate.
     *
     * The template makes the narrowing FLOW at the call site: the
     * caller's class-string list infers `$TParent`, and the declared
     * `MorphTo<TParent>` return delivers the static narrowing directly —
     * `allowlistedWith([PolyPost::class])` IS a `MorphTo<PolyPost>`, no
     * per-class wrapper method needed.
     *
     * @template TParent of Model
     *
     * @param list<class-string<TParent>> $types The allowed morph aliases.
     * @return MorphTo<TParent> The constrained
     *         relation, statically narrowed to the caller's list.
     */
    public function allowlistedWith(array $types): MorphTo
    {
        return $this->morphTo('commentable', null, null, null, $types);
    }
}
