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
     * @param  string  $propertyName
     * @param  string  $columnName
     * @param  Column  $column
     * @param  \ReflectionProperty|null  $property
     * @param  string  $owner
     * @param  string|null  $propertyType
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
