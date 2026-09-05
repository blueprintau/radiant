<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * A fluent column definition for a `CREATE TABLE` / `ALTER TABLE`.
 *
 * Mirrors the fields of the ORM's `#[Column]` attribute (§14 of the plan) so
 * a model's metadata can drive DDL directly. The schema layer is DB-only:
 * it lives under `Database\` and is consumed by
 * {@see \BlueprintAU\Radiant\Database\Connections\SqlConnection::create()}
 * and {@see alter()}, independent of the ORM.
 *
 * @phpstan-type ColumnShape array{
 *     type: ColumnType,
 *     name: string,
 *     primaryKey: bool,
 *     autoIncrement: bool,
 *     nullable: bool,
 *     unique: bool,
 *     index: bool,
 *     length: int|null,
 *     default: mixed,
 *     foreign: string|null,
 *     onDelete: string|null,
 *     onUpdate: string|null,
 * }
 */
final class Blueprint
{
    /**
     * The columns to create, in declaration order.
     *
     * @var list<ColumnShape>
     */
    private array $columns = [];

    /**
     * The columns to drop (ALTER only).
     *
     * @var list<string>
     */
    private array $dropColumns = [];

    /**
     * Indexes (single or composite), each with its own name.
     *
     * @var list<array{name: string, columns: list<string>, unique: bool}>
     */
    private array $indexes = [];

    /**
     * Add an index over one or more columns.
     *
     * A single column gets a custom-named index; multiple columns form a
     * composite. The name is used (prefixed by the table) for the
     * `CREATE INDEX` statement.
     *
     * @param string $name The index name (also used for the `CREATE INDEX`).
     * @param list<string> $columns The columns to index.
     * @param bool $unique Whether the index is unique.
     * @return $this
     * @throws \InvalidArgumentException When no columns are given.
     */
    public function index(string $name, array $columns, bool $unique = false): static
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('An index requires at least one column.');
        }

        $this->indexes[] = [
            'name' => $name,
            'columns' => $columns,
            'unique' => $unique,
        ];
        return $this;
    }

    /**
     * Add a column to the table.
     *
     * @param ColumnType $type The column type.
     * @param string $name The column name.
     * @param bool $primaryKey Whether this is the primary key.
     * @param bool $autoIncrement Whether the column auto-increments.
     * @param bool $nullable Whether the column allows null.
     * @param bool $unique Whether the column has a unique constraint.
     * @param bool $index Whether the column has a plain index.
     * @param int|null $length The column length (required for
     *        {@see ColumnType::String}).
     * @param mixed $default The column default.
     * @param string|null $foreign A foreign key reference, `table.column`.
     * @param string|null $onDelete The foreign key ON DELETE action.
     * @param string|null $onUpdate The foreign key ON UPDATE action.
     * @return $this
     */
    public function column(
        ColumnType $type,
        string $name,
        bool $primaryKey = false,
        bool $autoIncrement = false,
        bool $nullable = false,
        bool $unique = false,
        bool $index = false,
        ?int $length = null,
        mixed $default = null,
        ?string $foreign = null,
        ?string $onDelete = null,
        ?string $onUpdate = null,
    ): static {
        $this->columns[] = [
            'type' => $type,
            'name' => $name,
            'primaryKey' => $primaryKey,
            'autoIncrement' => $autoIncrement,
            'nullable' => $nullable,
            'unique' => $unique,
            'index' => $index,
            'length' => $length,
            'default' => $default,
            'foreign' => $foreign,
            'onDelete' => $onDelete,
            'onUpdate' => $onUpdate,
        ];
        return $this;
    }

    /**
     * Add a primary-key column.
     *
     * @param string $name The column name.
     * @param ColumnType $type The column type (default {@see ColumnType::BigInt}).
     * @param bool $autoIncrement Whether the key auto-increments.
     * @return $this
     */
    public function id(string $name = 'id', ColumnType $type = ColumnType::BigInt, bool $autoIncrement = true): static
    {
        return $this->column($type, $name, primaryKey: true, autoIncrement: $autoIncrement);
    }

    /**
     * Add a string column.
     *
     * @param string $name The column name.
     * @param int $length The column length (required).
     * @return $this
     */
    public function string(string $name, int $length): static
    {
        return $this->column(ColumnType::String, $name, length: $length);
    }

    /**
     * Add a nullable datetime column.
     *
     * @param string $name The column name.
     * @return $this
     */
    public function timestamp(string $name): static
    {
        return $this->column(ColumnType::DateTime, $name, nullable: true);
    }

    /**
     * Add a foreign-key column referencing another table.
     *
     * @param string $name The column name.
     * @param string $references The referenced table and column, `table.column`.
     * @param ColumnType $type The column type (default {@see ColumnType::BigInt}).
     * @param int|null $length The column length (required when the type is
     *        {@see ColumnType::String}).
     * @param string|null $onDelete The ON DELETE action.
     * @param string|null $onUpdate The ON UPDATE action.
     * @return $this
     */
    public function foreignId(
        string $name,
        string $references,
        ColumnType $type = ColumnType::BigInt,
        ?int $length = null,
        ?string $onDelete = null,
        ?string $onUpdate = null,
    ): static {
        return $this->column($type, $name, length: $length, foreign: $references, onDelete: $onDelete, onUpdate: $onUpdate);
    }

    /**
     * Foreign-key constraints — single-column via `foreignId()` are inline;
     * this holds table-level (composite) constraints.
     *
     * @var list<array{columns: list<string>, references: list<string>, onDelete: string|null, onUpdate: string|null}>
     */
    private array $foreignKeys = [];

    /**
     * Add a foreign-key constraint over one or more columns.
     *
     * Use this for composite foreign keys (e.g. a join table referencing a
     * composite primary key). The referenced table and columns must have
     * matching arity.
     *
     * @param list<string> $columns The local columns.
     * @param string $referencesTable The referenced table.
     * @param list<string> $referencesColumns The referenced columns.
     * @param string|null $onDelete The ON DELETE action.
     * @param string|null $onUpdate The ON UPDATE action.
     * @return $this
     * @throws \InvalidArgumentException When the column/reference arity
     *         mismatches or either list is empty.
     */
    public function foreignKey(
        array $columns,
        string $referencesTable,
        array $referencesColumns,
        ?string $onDelete = null,
        ?string $onUpdate = null,
    ): static {
        if ($columns === [] || $referencesColumns === []) {
            throw new \InvalidArgumentException('A foreign key requires at least one column.');
        }
        if (count($columns) !== count($referencesColumns)) {
            throw new \InvalidArgumentException(
                'Foreign key columns and references must have matching arity; got '
                . count($columns) . ' and ' . count($referencesColumns) . '.'
            );
        }

        $this->foreignKeys[] = [
            'columns' => $columns,
            'references' => [$referencesTable, ...$referencesColumns],
            'onDelete' => $onDelete,
            'onUpdate' => $onUpdate,
        ];
        return $this;
    }

    /**
     * Foreign-key constraints — derived single-column plus explicit composite.
     *
     * Single-column FKs come from columns declared with `foreign` (parsed
     * from the `table.column` reference). Explicit {@see foreignKey()}
     * declarations (single or composite) are appended after. Each entry's
     * `references` is `[table, ...columns]`.
     *
     * @return list<array{columns: list<string>, references: list<string>, onDelete: string|null, onUpdate: string|null}>
     */
    public function getForeignKeys(): array
    {
        $foreignKeys = [];

        foreach ($this->columns as $column) {
            if ($column['foreign'] === null) {
                continue;
            }

            $reference = explode('.', $column['foreign']);
            if (count($reference) !== 2) {
                throw new \InvalidArgumentException(
                    'Foreign key reference must be "table.column"; got ' . $column['foreign'] . '.'
                );
            }

            $foreignKeys[] = [
                'columns' => [$column['name']],
                'references' => [$reference[0], $reference[1]],
                'onDelete' => $column['onDelete'],
                'onUpdate' => $column['onUpdate'],
            ];
        }

        return [...$foreignKeys, ...$this->foreignKeys];
    }

    /**
     * Drop a column (ALTER only).
     *
     * @param string $name The column name.
     * @return $this
     */
    public function dropColumn(string $name): static
    {
        $this->dropColumns[] = $name;
        return $this;
    }

    /**
     * The columns to create.
     *
     * @return list<ColumnShape>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * The columns to drop.
     *
     * @return list<string>
     */
    public function getDropColumns(): array
    {
        return $this->dropColumns;
    }

    /**
     * The indexes — derived single-column plus explicit composite.
     *
     * Single-column indexes come from columns declared with `index: true`
     * (named by the column), unless the column is also `unique: true` (the
     * inline UNIQUE constraint already covers it). Explicit {@see index()}
     * declarations (single or composite) are appended after.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    public function getIndexes(): array
    {
        $indexes = [];

        foreach ($this->columns as $column) {
            if ($column['index'] === true && $column['unique'] !== true) {
                $indexes[] = [
                    'name' => $column['name'],
                    'columns' => [$column['name']],
                    'unique' => false,
                ];
            }
        }

        return [...$indexes, ...$this->indexes];
    }
}