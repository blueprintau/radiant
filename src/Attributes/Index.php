<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Declares an index over one or more columns (class-level).
 *
 * Single-column convenience stays on {@see Column}'s `index` flag; use this
 * attribute for composite indexes — or any index needing a custom name the
 * flag cannot express. Column names are validated against the model's
 * `#[Column]` set at build time by the {@see \BlueprintAU\Radiant\Metadata\MetadataFactory}.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Index
{
    /**
     * Create an index declaration.
     *
     * @param list<string> $columns Column names, validated at build time.
     * @param string|null $name The index name; defaults to the dialect's
     *        derived name when null.
     */
    public function __construct(
        public array $columns,
        public ?string $name = null,
    ) {
    }
}
