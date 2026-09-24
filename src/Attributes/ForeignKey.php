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
     * @param  list<string>  $columns
     * @param  ForeignKeyReference  $references
     * @param  list<string>|null  $referencesColumns
     * @param  ForeignKeyAction|string|null  $onDelete
     * @param  ForeignKeyAction|string|null  $onUpdate
     * @param  bool  $deferrable
     * @param  bool  $initiallyDeferred  Implies `$deferrable`.
     */
    public function __construct(
        public array $columns,
        public string $references,
        public array|null $referencesColumns = null,
        public ForeignKeyAction|string|null $onDelete = null,
        public ForeignKeyAction|string|null $onUpdate = null,
        public bool $deferrable = false,
        public bool $initiallyDeferred = false,
    ) {
    }

    /**
     * The resolved referenced table name.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function resolvedReferences(): string
    {
        return ReferenceResolver::resolve($this->references);
    }

    /**
     * The resolved referenced columns.
     *
     * @return list<string>
     * @throws \InvalidArgumentException
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
