<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Declares a UNIQUE constraint over one or more columns (class-level).
 *
 * Single-column convenience stays on {@see Column}'s `unique` flag; use this
 * attribute for composite constraints — or any constraint needing a custom
 * configuration the flag cannot express. One mental rule: **a constraint
 * over more than one column — or one needing explicit configuration — is a
 * class-level attribute.**
 *
 * Column names are strings (PHP attributes cannot reference other
 * properties); the {@see \BlueprintAU\Radiant\Metadata\MetadataFactory} validates every name against the
 * model's `#[Column]` set at build time, so a renamed property fails loudly
 * at build, not as a broken constraint in the database.
 *
 * A flag and an attribute covering the same single column is a duplicate
 * declaration and fails fast at build — the two mechanisms can never
 * silently double-declare.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Unique
{
    /**
     * Create a unique-constraint declaration.
     *
     * @param list<string> $columns Column names, validated against the
     *        model's `#[Column]` set at build time.
     * @param string|null $name The constraint/index name; defaults to a
     *        derivation from the covered columns (`{columns}_unique`,
     *        rendered `{table}_{name}_unique` by the grammar) when null.
     *        Set it when the derived name is ambiguous (two uniques whose
     *        column concatenations could collide) or when the host's naming
     *        convention demands a specific name.
     */
    public function __construct(
        public array $columns,
        public string|null $name = null,
    ) {
    }
}
