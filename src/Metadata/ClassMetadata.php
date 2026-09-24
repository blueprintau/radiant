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
     * The column → owning-table map (computed by the factory at build).
     *
     * @var array<string, string>
     */
    private readonly array $tablePartitions;

    /**
     * The DB column name → mapping hash map (precomputed in the
     * constructor).
     *
     * @var array<string, PropertyMapping>
     */
    private readonly array $columnsByDbName;

    /**
     * Create class metadata.
     *
     * @param  string|null  $tableName
     * @param  PropertyMapping[]  $properties
     * @param  list<Column>  $primaryKeys
     * @param  list<Unique>  $uniques
     * @param  list<Index>  $indexes
     * @param  list<ForeignKey>  $foreignKeys
     * @param  list<Check>  $checks
     * @param  string|null  $softDeleteColumn  The column name when the class uses {@see SoftDeletes}.
     * @param  class-string<Model>|null  $parentModel
     * @param  array<string, string>  $tablePartitions
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
     * @param  string  $columnName
     * @return PropertyMapping
     * @throws \InvalidArgumentException
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
     * @param  string  $columnName
     * @return bool
     */
    public function hasColumn(string $columnName): bool
    {
        return isset($this->columnsByDbName[$columnName])
            || array_key_exists($columnName, $this->columnsByDbName);
    }

    /**
     * Whether this class is a multi-table-inheritance child.
     *
     * @return bool
     */
    public function isMtiChild(): bool
    {
        return $this->parentModel !== null;
    }

    /**
     * The table that owns a given column — the partition map.
     *
     * @param  string  $columnName
     * @return string
     * @throws \InvalidArgumentException
     */
    public function tableFor(string $columnName): string
    {
        return $this->tablePartitions[$columnName]
            ?? throw new \InvalidArgumentException(
                'Unknown column [' . $columnName . '] on model [' . ($this->tableName ?? 'no table') . '].'
            );
    }
}
