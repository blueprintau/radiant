<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Declares a polymorphic (morph) column pair on the model's table.
 *
 * One attribute emits TWO synthetic columns: `{name}_type` (a string
 * holding the related model's class-string — the morph alias) and
 * `{name}_id` (the related model's primary-key value). Together they
 * point at a row of ANY model table — the polymorphic target.
 *
 * The columns are SYNTHETIC mappings (no PHP property backs them), the
 * same mechanism the soft-delete column uses: values live on the model's
 * runtime attribute store, read and written through
 * {@see \BlueprintAU\Radiant\Model::attribute()} /
 * {@see \BlueprintAU\Radiant\Model::setAttribute()}. The relations
 * (`morphTo`/`morphOne`/`morphMany`) read the pair through those
 * accessors, so a morph column needs no declared property to work.
 *
 * The DDL side is shared with hand-built blueprints:
 * {@see \BlueprintAU\Radiant\Database\Schema\Blueprint::morphs()} emits
 * the identical column pair, and `Blueprint::fromMetadata()` folds this
 * attribute BY CALLING that helper — one emission path, so a
 * metadata-driven table and a hand-built one always agree.
 *
 * The morph alias convention is the related model's FULL class-string
 * (FQCN): unambiguous across namespaces (a short name would silently
 * corrupt resolution when two modules each declare a `Comment`), and
 * consistent with what the relations write. The trade — renaming a class
 * changes the stored alias — is a data-migration concern, documented in
 * docs/relations.md; an alias registry can be added later without
 * changing the convention.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Morphs
{
    /**
     * Create a morph-pair declaration.
     *
     * @param  string  $name
     * @param  bool  $nullable
     */
    public function __construct(
        public string $name,
        public bool $nullable = false,
    ) {
    }

    /**
     * The type-discriminator column name this declaration emits.
     *
     * @return string
     */
    public function typeColumn(): string
    {
        return $this->name . '_type';
    }

    /**
     * The key column name this declaration emits.
     *
     * @return string
     */
    public function keyColumn(): string
    {
        return $this->name . '_id';
    }
}
