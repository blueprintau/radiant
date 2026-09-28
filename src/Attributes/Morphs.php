<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * Declares a polymorphic (morph) column pair on the model's table.
 *
 * Emits two synthetic columns — `{name}_type` (the related model's
 * class-string) and `{name}_id` (its primary-key value) — read and
 * written through {@see \BlueprintAU\Radiant\Model::attribute()} /
 * {@see \BlueprintAU\Radiant\Model::setAttribute()}. The key column's
 * type defaults to bigint; declare `keyType:` when the morph targets use
 * a different primary-key type. The alias convention is the related
 * model's full class-string; renaming a class changes the stored alias —
 * a data-migration concern documented in docs/relations.md.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Morphs
{
    /**
     * Create a morph-pair declaration.
     *
     * @param  string  $name
     * @param  bool  $nullable
     * @param  ColumnType  $keyType  The `{name}_id` column's type; every morph target's primary key must match it.
     */
    public function __construct(
        public string $name,
        public bool $nullable = false,
        public ColumnType $keyType = ColumnType::BigInt,
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

    /**
     * Assert the declared key type can hold a primary-key value.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    public function assertKeyTypeCapable(): void
    {
        if (!$this->keyType->primaryKeyCapable()) {
            throw new \InvalidArgumentException(
                "#[Morphs(name: '{$this->name}')] declares keyType [{$this->keyType->value}], "
                . 'which cannot hold a primary-key value; use an integer, string, char, or uuid type.'
            );
        }
    }
}
