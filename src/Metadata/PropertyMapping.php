<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Metadata;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Model;

/**
 * One column's resolved metadata: the property, its DB column, and who owns it.
 *
 * @property class-string<Model> $owner
 */
final class PropertyMapping
{
    /**
     * Create a property mapping.
     *
     * @param string $propertyName The PHP property name.
     * @param string $columnName The DB column name (the `#[Column]` name when
     *        declared, otherwise the property name).
     * @param Column $column The column declaration.
     * @param \ReflectionProperty|null $property The reflected property.
     *        Null ONLY for a synthetic mapping — the soft-delete column the
     *        factory injects when the class declares no property of that
     *        name; a `ReflectionProperty` cannot be constructed for a
     *        property that does not exist.
     * @param string $owner The class that DECLARES the column property —
     *        the hierarchy level the column belongs to. Recorded during
     *        build()'s property pass as `getDeclaringClass()`. v1 uses it
     *        for the ownership flags in table resolution and for
     *        diagnostics; the deferred JOINED strategy consumes it to
     *        partition columns per table: `owner === root` → root table,
     *        `owner === child` → child table.
     * @param string|null $propertyType The PHP property type NAME driving
     *        the cast pipeline (`'Carbon\\Carbon'`, `'int'`, `'array'`),
     *        captured from the `ReflectionProperty` by the factory. Null
     *        for an untyped property — a build error for a declared
     *        column, but legitimate for a synthetic mapping (whose cast
     *        pins to the column type's name).
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly string $columnName,
        public readonly Column $column,
        public readonly \ReflectionProperty|null $property,
        public readonly string $owner,
        public readonly string|null $propertyType = null,
    ) {
    }
}
