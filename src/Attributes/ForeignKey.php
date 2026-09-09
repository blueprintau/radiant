<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * Declares a foreign-key constraint over one or more columns (class-level).
 *
 * Single-column convenience stays on {@see Column}'s `foreign` flag; use
 * this attribute for composite FKs — or any FK needing actions/shape the
 * flag cannot express. `references` accepts EITHER a table name string OR
 * a model class-string — a model class-string resolves to its table name
 * through the {@see MetadataFactory} at build time (the same convention the
 * model itself uses), so a renamed table never breaks the FK silently.
 *
 * `referencesColumns` may be LEFT NULL when `references` is a model
 * class-string: it then defaults to the target model's full primary-key
 * column list (single or composite — a composite-PK target works out of
 * the box). With an explicit `referencesColumns` the arity must match
 * `columns` (mirroring `Blueprint::foreignKey()`); the {@see MetadataFactory}
 * validates names and arity at build time and emits exactly one
 * `Blueprint::foreignKey()` call per declaration.
 *
 * @phpstan-import-type ForeignKeyReference from \BlueprintAU\Radiant\Attributes\ReferenceResolver
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class ForeignKey
{
    /**
     * Create a foreign-key declaration.
     *
     * @param list<string> $columns Local column names, validated at build time.
     * @param ForeignKeyReference $references The referenced table — a table
     *        name string, or a model class-string (resolved to its table
     *        name through the metadata at build time).
     * @param list<string>|null $referencesColumns The referenced columns —
     *        null defaults to the referenced MODEL's full primary-key list
     *        (a table-name reference must declare them explicitly); when
     *        given, must have matching arity with `$columns` (fail-fast in
     *        the factory).
     * @param ForeignKeyAction|string|null $onDelete The ON DELETE action —
     *        validated via {@see ForeignKeyAction::fromChecked()} at the DDL
     *        boundary.
     * @param ForeignKeyAction|string|null $onUpdate The ON UPDATE action.
     */
    public function __construct(
        public array $columns,
        public string $references,
        public array|null $referencesColumns = null,
        public ForeignKeyAction|string|null $onDelete = null,
        public ForeignKeyAction|string|null $onUpdate = null,
    ) {
    }

    /**
     * The resolved referenced table name.
     *
     * A plain table name passes through; a model class-string resolves via
     * the shared {@see ReferenceResolver} — the same rule the schema
     * layer's single-column `foreign:` flag uses.
     *
     * @return string The referenced table name.
     * @throws \InvalidArgumentException When a model class-string does not
     *         exist, or resolves to no table (a column-less model cannot be
     *         FK-referenced).
     */
    public function resolvedReferences(): string
    {
        return ReferenceResolver::resolve($this->references);
    }

    /**
     * The resolved referenced columns.
     *
     * Explicit `referencesColumns` pass through; a null list resolves from
     * the referenced MODEL's full primary-key column list (single or
     * composite) — the caller-declared `$columns` must then have matching
     * arity (checked by the factory's arity guard). A table-name reference
     * with no `referencesColumns` falls back to the `id` convention.
     *
     * @return list<string> The referenced column names.
     * @throws \InvalidArgumentException When a model class-string does not
     *         exist or resolves to no table.
     */
    public function resolvedReferencesColumns(): array
    {
        if ($this->referencesColumns !== null) {
            return $this->referencesColumns;
        }

        if (!str_contains($this->references, '\\')) {
            return ['id']; // plain table + no columns → the PK convention.
        }

        // resolve() validated existence + Model-ness; the is_a guard
        // narrows the string to class-string<Model> for the metadata call.
        $model = $this->references;

        if (!is_a($model, \BlueprintAU\Radiant\Model::class, true)) {
            throw new \LogicException(
                "Reference [{$model}] resolved as a model but is not one."
            );
        }

        $metadata = MetadataFactory::for($model);

        return array_map(
            fn (Column $primaryKey) => $primaryKey->name ?? '',
            $metadata->primaryKeys,
        );
    }
}
