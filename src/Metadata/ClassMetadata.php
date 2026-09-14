<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Metadata;

use BlueprintAU\Radiant\Attributes\Check;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Attributes\Unique;

/**
 * A model class's cached metadata: table name, columns, keys, constraints.
 *
 * Built once per class by {@see MetadataFactory} and cached — class metadata
 * is immutable, so the static cache is justified. `$tableName` is null for
 * any class with no `#[Column]` properties of its own — abstract
 * intermediates and concrete organizational bases alike (rule 4);
 * `MetadataFactory::tables()` skips them. Constraints on such a class are
 * still collected — a rule-4 class's constraints travel with its merged
 * columns into the descendant's table.
 */
final class ClassMetadata
{
    /**
     * The column → owning-table map (computed lazily).
     *
     * @var array<string, string>|null
     */
    private array|null $tablePartitions = null;

    /**
     * The DB column name → mapping hash map (computed lazily).
     *
     * The hot paths — `attribute()`, `castForWrite()`, key reads during
     * eager matching — used to walk the property-keyed list per call
     * (O(columns) each, O(columns × rows) per load). This map makes every
     * lookup O(1).
     *
     * @var array<string, PropertyMapping>|null
     */
    private array|null $columnsByDbName = null;

    /**
     * Create class metadata.
     *
     * @param string|null $tableName The resolved table name, or null for a
     *        class with no columns of its own (rule 4).
     * @param PropertyMapping[] $properties The merged column mappings,
     *        keyed by property name (leaf declaration wins).
     * @param list<Column> $primaryKeys The primary-key column declarations.
     * @param list<Unique> $uniques Every `#[Unique]` on the class hierarchy.
     *        Column names are validated against `$properties` at build.
     * @param list<Index> $indexes Every `#[Index]`.
     * @param list<ForeignKey> $foreignKeys Every `#[ForeignKey]`.
     * @param list<Check> $checks Every `#[Check]` on the class hierarchy.
     * @param string|null $softDeleteColumn The resolved soft-delete column
     *        name when the class uses {@see SoftDeletes}, else null. Carried
     *        on the metadata so query building can apply the scope without
     *        calling the trait's static method on a model class that may
     *        not have it.
     * @param class-string<Model>|null $parentModel The nearest TABLE-OWNING
     *        ancestor when this class is a multi-table-inheritance child —
     *        a concrete subclass that declares its own `#[Table]` (and, by
     *        the derivation rules, its own columns), whose table holds its
     *        own columns while the ancestor's table holds the inherited
     *        ones. Null for every non-MTI class.
     */
    public function __construct(
        public readonly ?string $tableName,
        public readonly array $properties,
        public readonly array $primaryKeys,
        public readonly array $uniques = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
        public readonly array $checks = [],
        public readonly ?string $softDeleteColumn = null,
        public readonly string|null $parentModel = null,
    ) {
    }

    /**
     * The mapping for a DB column name — O(1) via the hash map.
     *
     * @param string $columnName The DB column name.
     * @return PropertyMapping The mapping.
     * @throws \InvalidArgumentException When the column is unknown.
     */
    public function mappingFor(string $columnName): PropertyMapping
    {
        $map = $this->columnsByDbName ??= $this->buildColumnsByDbName();

        return $map[$columnName]
            ?? throw new \InvalidArgumentException(
                'Unknown column [' . $columnName . '] on model [' . ($this->tableName ?? 'no table') . '].'
            );
    }

    /**
     * Whether a DB column name exists on this class — O(1).
     *
     * @param string $columnName The DB column name.
     * @return bool True when the column is declared.
     */
    public function hasColumn(string $columnName): bool
    {
        $map = $this->columnsByDbName ??= $this->buildColumnsByDbName();

        return isset($map[$columnName]) || array_key_exists($columnName, $map);
    }

    /**
     * Build the DB column name → mapping map.
     *
     * @return array<string, PropertyMapping> column => mapping
     */
    private function buildColumnsByDbName(): array
    {
        $map = [];

        foreach ($this->properties as $mapping) {
            $map[$mapping->columnName] = $mapping;
        }

        return $map;
    }

    /**
     * Whether this class is a multi-table-inheritance child.
     *
     * @return bool True when the class owns its own table AND an ancestor
     *         table holds the inherited columns.
     */
    public function isMtiChild(): bool
    {
        return $this->parentModel !== null;
    }

    /**
     * The table that owns a given column — the partition map.
     *
     * Every `#[Column]` mapping records the class that declared it
     * ({@see PropertyMapping::$owner}); the partition resolves that owner
     * to its table through the metadata cache. For a plain model the answer
     * is always its own table; for an MTI child the inherited columns
     * resolve to the ancestor's table and the own columns to the child's.
     *
     * @param string $columnName The DB column name.
     * @return string The owning table name.
     * @throws \InvalidArgumentException When the column is unknown.
     */
    public function tableFor(string $columnName): string
    {
        $partitions = $this->tablePartitions ??= $this->buildPartitions();

        return $partitions[$columnName]
            ?? throw new \InvalidArgumentException(
                'Unknown column [' . $columnName . '] on model [' . ($this->tableName ?? 'no table') . '].'
            );
    }

    /**
     * Build the column → owning-table map from the merged property
     * mappings — each mapping's declaring class resolves to its table
     * through the metadata cache.
     *
     * @return array<string, string> column => table
     */
    private function buildPartitions(): array
    {
        $partitions = [];

        foreach ($this->properties as $mapping) {
            $table = MetadataFactory::for($mapping->owner)->tableName;

            if ($table === null) {
                continue; // abstract owner — merged into a descendant's table
            }

            $partitions[$mapping->columnName] = $table;
        }

        return $partitions;
    }
}
