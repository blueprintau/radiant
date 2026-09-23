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
     * The column → owning-table map (computed by the factory at build —
     * resolving an owner's table must consult the metadata cache, and the
     * class's OWN entry is not seeded until construction returns, so the
     * self-reference is resolved by the factory, which already knows the
     * table name).
     *
     * @var array<string, string>
     */
    private readonly array $tablePartitions;

    /**
     * The DB column name → mapping hash map (precomputed in the
     * constructor).
     *
     * The hot paths — `attribute()`, `castForWrite()`, key reads during
     * eager matching — used to walk the property-keyed list per call
     * (O(columns) each, O(columns × rows) per load). This map makes every
     * lookup O(1).
     *
     * @var array<string, PropertyMapping>
     */
    private readonly array $columnsByDbName;

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
     * @param array<string, string> $tablePartitions The column →
     *        owning-table map, precomputed by the factory (see the
     *        property docblock for why it cannot be derived here).
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
        array $tablePartitions = [],
    ) {
        // Eager precompute: the class is built once per process (the
        // MetadataFactory cache), so deriving the lookup map here costs
        // nothing and makes the instance truly readonly — no `??=` write can
        // ever race a concurrent reader (Fiber/Swoole re-entrancy on the
        // shared metadata cache).
        $columnsByDbName = [];

        foreach ($properties as $mapping) {
            $columnsByDbName[$mapping->columnName] = $mapping;
        }

        $this->columnsByDbName = $columnsByDbName;
        $this->tablePartitions = $tablePartitions;
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
        return $this->columnsByDbName[$columnName]
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
        return isset($this->columnsByDbName[$columnName])
            || array_key_exists($columnName, $this->columnsByDbName);
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
        return $this->tablePartitions[$columnName]
            ?? throw new \InvalidArgumentException(
                'Unknown column [' . $columnName . '] on model [' . ($this->tableName ?? 'no table') . '].'
            );
    }
}
