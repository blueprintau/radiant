<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ReferenceResolver;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * A fluent column definition for a `CREATE TABLE` / `ALTER TABLE`.
 *
 * Uses the same field vocabulary the ORM's `#[Column]` attribute uses
 * (type, length, nullable, unique, index, foreign, …) so a model's
 * metadata can drive DDL directly.
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
 *     precision: int|null,
 *     default: mixed,
 *     foreign: string|null,
 *     onDelete: ForeignKeyAction|null,
 *     onUpdate: ForeignKeyAction|null,
 * }
 */
final class Blueprint
{
    /**
     * The table this blueprint builds.
     *
     * @var string
     */
    private readonly string $table;

    /**
     * The table this blueprint renames, when it declares a table rename.
     *
     * @var string|null
     */
    private string|null $renamedFrom = null;

    /**
     * The column renames declared on this blueprint.
     *
     * @var list<array{from: string, to: string}>
     */
    private array $columnRenames = [];

    /**
     * Create a table-bound blueprint.
     *
     * @param  string  $table
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * The table this blueprint builds.
     *
     * @return string
     */
    final public function getTable(): string
    {
        return $this->table;
    }

    /**
     * A copy of this blueprint bound to a different table name.
     *
     * Indexes are not carried — derived index names embed the table name,
     * and the rebuild re-creates them from the original blueprint after
     * the rename.
     *
     * @param  string  $table
     * @return static
     */
    public function forTable(string $table): static
    {
        // `$table` is readonly, so the rebind goes through the
        // constructor rather than a clone-assign.
        $copy = new static($table);

        $copy->columns = $this->columns;
        $copy->foreignKeys = $this->foreignKeys;
        $copy->checks = $this->checks;
        $copy->columnRenames = $this->columnRenames;
        $copy->renamedFrom = $this->renamedFrom;

        $copy->indexes = [];

        return $copy;
    }

    /**
     * Declare that this blueprint renames an existing table.
     *
     * @param  string  $oldTable
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function renamedFrom(string $oldTable): static
    {
        if (trim($oldTable) === '') {
            throw new \InvalidArgumentException('A table rename requires a non-empty old table name.');
        }

        $clone = clone $this;
        $clone->renamedFrom = $oldTable;
        return $clone;
    }

    /**
     * The table this blueprint renames, or null when it is not a rename.
     *
     * @return string|null
     */
    final public function getRenamedFrom(): string|null
    {
        return $this->renamedFrom;
    }

    /**
     * Declare a column rename: the live column `$from` becomes `$to`.
     *
     * @param  string  $from
     * @param  string  $to
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function renameColumn(string $from, string $to): static
    {
        if (trim($from) === '' || trim($to) === '') {
            throw new \InvalidArgumentException('A column rename requires non-empty column names.');
        }

        if ($from === $to) {
            throw new \InvalidArgumentException(
                "A column rename requires different names; got [{$from}] -> [{$to}]."
            );
        }

        $clone = clone $this;
        $clone->columnRenames = [...$this->columnRenames, ['from' => $from, 'to' => $to]];
        return $clone;
    }

    /**
     * The declared column renames, in declaration order.
     *
     * @return list<array{from: string, to: string}>
     */
    final public function getColumnRenames(): array
    {
        return $this->columnRenames;
    }

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
     * Live foreign-key constraint names to drop (ALTER only) — the drop
     * handles captured by the inspector during diffing.
     *
     * @var list<string>
     */
    private array $dropForeignKeys = [];

    /**
     * Live CHECK constraint names to drop (ALTER only) — the drop
     * handles captured by the inspector during diffing.
     *
     * @var list<string>
     */
    private array $dropChecks = [];

    /**
     * Indexes (single or composite), each with its final name.
     *
     * @var list<array{name: string, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
     */
    private array $indexes = [];

    /**
     * Derive the final index name from its kind and columns.
     *
     * @param  list<string>  $columns
     * @param  bool  $unique
     * @return string
     */
    private function deriveIndexName(array $columns, bool $unique): string
    {
        return ConstraintNamer::derive($this->table, $columns, $unique ? 'unique' : 'index');
    }

    /**
     * Normalize a `foreign` reference to its `table.column` form.
     *
     * @param  class-string<\BlueprintAU\Radiant\Model>|string  $foreign
     * @param  string  $column
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeForeignReference(string $foreign, string $column): string
    {
        // Already explicit `table.column`.
        if (str_contains($foreign, '.')) {
            if (count(explode('.', $foreign)) !== 2) {
                throw new \InvalidArgumentException(
                    'Foreign key reference must be "table.column"; got ' . $foreign . '.'
                );
            }

            return $foreign;
        }

        $table = ReferenceResolver::resolve($foreign);

        // The referenced model's single PK (if declared) gives the column;
        // fall back to the `id` convention for tables the ORM does not own.
        $pkColumn = 'id';

        if (str_contains($foreign, '\\')) {
            if (!is_a($foreign, Model::class, true)) {
                throw new \LogicException(
                    "Reference [{$foreign}] resolved as a model but is not one."
                );
            }

            $pks = MetadataFactory::for($foreign)->primaryKeys;

            if (count($pks) === 1) {
                $pkColumn = $pks[0]->name ?? $pkColumn;
            } elseif (count($pks) > 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Column [%s] references model [%s], which has a composite primary '
                    . 'key; a single-column foreign key cannot reference it. Declare a '
                    . 'class-level #[ForeignKey(columns: [...], references: %s::class)] '
                    . 'with the full column list instead.',
                    $column,
                    $foreign,
                    (new \ReflectionClass($foreign))->getShortName(),
                ));
            }
        }

        return $table . '.' . $pkColumn;
    }

    /**
     * Add an index over one or more columns.
     *
     * When `$name` is given it is the whole final name; when omitted the
     * name is derived as `{table}_{columns}_{kind}`.
     *
     * @param  string|null  $name
     * @param  list<string>  $columns
     * @param  bool  $unique
     * @param  string|null  $where  Partial-index predicate (Postgres, SQLite).
     * @param  bool  $nullsNotDistinct  `NULLS NOT DISTINCT` (Postgres 15+).
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function index(
        ?string $name,
        array $columns,
        bool $unique = false,
        ?string $where = null,
        bool $nullsNotDistinct = false,
    ): static {
        if ($columns === []) {
            throw new \InvalidArgumentException('An index requires at least one column.');
        }

        if ($where !== null && trim($where) === '') {
            throw new \InvalidArgumentException('An index `where` predicate, when given, must be non-empty.');
        }

        if ($nullsNotDistinct && !$unique) {
            throw new \InvalidArgumentException(
                'An index declares nullsNotDistinct without unique: NULLS NOT DISTINCT '
                . 'only applies to a UNIQUE index.'
            );
        }

        $clone = clone $this;
        $clone->indexes = [...$this->indexes, [
            'name' => $name ?? $this->deriveIndexName($columns, $unique),
            'columns' => $columns,
            'unique' => $unique,
            'where' => $where,
            'nullsNotDistinct' => $nullsNotDistinct,
        ]];
        return $clone;
    }

    /**
     * Add a column to the table.
     *
     * @param  ColumnType  $type
     * @param  string  $name
     * @param  bool  $primaryKey
     * @param  bool  $autoIncrement
     * @param  bool  $nullable
     * @param  bool  $unique
     * @param  bool  $index
     * @param  int|null  $length
     * @param  int|null  $precision
     * @param  mixed  $default
     * @param  string|null  $foreign
     * @param  ForeignKeyAction|string|null  $onDelete
     * @param  ForeignKeyAction|string|null  $onUpdate
     * @return static
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
        ?int $precision = null,
        mixed $default = null,
        ?string $foreign = null,
        ForeignKeyAction|string|null $onDelete = null,
        ForeignKeyAction|string|null $onUpdate = null,
    ): static {
        // Validate the reference at declaration time, and normalize it to
        // `table.column` so the getter is a pure read.
        if ($foreign !== null) {
            $foreign = $this->normalizeForeignReference($foreign, $name);
        }

        $clone = clone $this;
        $clone->columns = [...$this->columns, [
            'type' => $type,
            'name' => $name,
            'primaryKey' => $primaryKey,
            'autoIncrement' => $autoIncrement,
            'nullable' => $nullable,
            'unique' => $unique,
            'index' => $index,
            'length' => $length,
            'precision' => $precision,
            'default' => $default,
            'foreign' => $foreign,
            'onDelete' => $onDelete === null ? null : ($onDelete instanceof ForeignKeyAction ? $onDelete : ForeignKeyAction::fromChecked($onDelete)),
            'onUpdate' => $onUpdate === null ? null : ($onUpdate instanceof ForeignKeyAction ? $onUpdate : ForeignKeyAction::fromChecked($onUpdate)),
        ]];

        // A flagged plain index derives its final name here (`unique: true`
        // rides the column's inline UNIQUE constraint, so no separate
        // index is needed).
        if ($index === true && $unique !== true) {
            $clone->indexes = [...$clone->indexes, [
                'name' => $this->deriveIndexName([$name], false),
                'columns' => [$name],
                'unique' => false,
                'where' => null,
                'nullsNotDistinct' => false,
            ]];
        }

        return $clone;
    }

    /**
     * Add a primary-key column.
     *
     * @param  string  $name
     * @param  ColumnType  $type
     * @param  bool  $autoIncrement
     * @return static
     */
    public function id(string $name = 'id', ColumnType $type = ColumnType::BigInt, bool $autoIncrement = true): static
    {
        return $this->column($type, $name, primaryKey: true, autoIncrement: $autoIncrement);
    }

    /**
     * Add a string column.
     *
     * @param  string  $name
     * @param  int  $length
     * @return static
     */
    public function string(string $name, int $length): static
    {
        return $this->column(ColumnType::String, $name, length: $length);
    }

    /**
     * Add a nullable datetime column.
     *
     * @param  string  $name
     * @param  int|null  $precision  Fractional-seconds digits (1–6); null stores whole seconds.
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function timestamp(string $name, ?int $precision = null): static
    {
        self::assertPrecision($precision);

        return $this->column(ColumnType::DateTime, $name, nullable: true, precision: $precision);
    }

    /**
     * Add a nullable datetime column (an explicit alias of `timestamp()`).
     *
     * @param  string  $name
     * @param  int|null  $precision  Fractional-seconds digits (1–6); null stores whole seconds.
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function datetime(string $name, ?int $precision = null): static
    {
        return $this->timestamp($name, $precision);
    }

    /**
     * Add `created_at` / `updated_at` datetime columns.
     *
     * @param  int|null  $precision  Fractional-seconds digits (1–6); null stores whole seconds.
     * @param  bool  $nullable  Whether the columns allow null.
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function timestamps(?int $precision = null, bool $nullable = false): static
    {
        return $this
            ->column(ColumnType::DateTime, 'created_at', nullable: $nullable, precision: $precision)
            ->column(ColumnType::DateTime, 'updated_at', nullable: $nullable, precision: $precision);
    }

    /**
     * Add a nullable `deleted_at` datetime column for soft deletes.
     *
     * @param  int|null  $precision  Fractional-seconds digits (1–6); null stores whole seconds.
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function softDeletes(?int $precision = null): static
    {
        return $this->timestamp('deleted_at', $precision);
    }

    /**
     * Assert a fractional-seconds precision is in the portable range.
     *
     * MySQL and Postgres both accept 0–6 fractional digits; the schema
     * layer treats `null` as "whole seconds" and rejects anything above 6
     * (no portable dialect stores more) or below 1 (use `null`).
     *
     * @param  int|null  $precision
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private static function assertPrecision(?int $precision): void
    {
        if ($precision !== null && ($precision < 1 || $precision > 6)) {
            throw new \InvalidArgumentException(
                "Datetime precision [{$precision}] is out of range; use null for whole "
                . 'seconds or an integer between 1 and 6 for fractional seconds.'
            );
        }
    }

    /**
     * Add a foreign-key column referencing another table.
     *
     * @param  string  $name
     * @param  string  $references
     * @param  ColumnType  $type
     * @param  int|null  $length
     * @param  ForeignKeyAction|string|null  $onDelete
     * @param  ForeignKeyAction|string|null  $onUpdate
     * @return static
     */
    public function foreignId(
        string $name,
        string $references,
        ColumnType $type = ColumnType::BigInt,
        ?int $length = null,
        ForeignKeyAction|string|null $onDelete = null,
        ForeignKeyAction|string|null $onUpdate = null,
    ): static {
        return $this->column($type, $name, length: $length, foreign: $references, onDelete: $onDelete, onUpdate: $onUpdate);
    }

    /**
     * Add a polymorphic (morph) column pair: `{name}_type` + `{name}_id`.
     *
     * @param  string  $name
     * @param  bool  $nullable
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function morphs(string $name, bool $nullable = false): static
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A morph pair requires a non-empty name.');
        }

        return $this
            ->column(ColumnType::String, $name . '_type', nullable: $nullable, length: 255)
            ->column(ColumnType::BigInt, $name . '_id', nullable: $nullable);
    }

    /**
     * The table-level foreign-key constraints declared on this blueprint.
     *
     * @var list<array{name: string, columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}>
     */
    private array $foreignKeys = [];

    /**
     * The table-level CHECK constraints declared on this blueprint.
     *
     * @var list<array{name: string, expression: string}>
     */
    private array $checks = [];

    /**
     * Add a foreign-key constraint over one or more columns.
     *
     * Use this for composite foreign keys (e.g. a join table referencing
     * a composite primary key).
     *
     * @param  list<string>  $columns
     * @param  string  $referencesTable
     * @param  list<string>  $referencesColumns
     * @param  ForeignKeyAction|string|null  $onDelete
     * @param  ForeignKeyAction|string|null  $onUpdate
     * @param  bool  $deferrable  Postgres only.
     * @param  bool  $initiallyDeferred  Postgres only; implies `$deferrable`.
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function foreignKey(
        array $columns,
        string $referencesTable,
        array $referencesColumns,
        ForeignKeyAction|string|null $onDelete = null,
        ForeignKeyAction|string|null $onUpdate = null,
        bool $deferrable = false,
        bool $initiallyDeferred = false,
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
        if ($initiallyDeferred && !$deferrable) {
            throw new \InvalidArgumentException(
                'A foreign key declares initiallyDeferred without deferrable: '
                . 'INITIALLY DEFERRED implies DEFERRABLE.'
            );
        }

        $clone = clone $this;
        $clone->foreignKeys = [...$this->foreignKeys, [
            'name' => ConstraintNamer::derive($this->table, $columns, 'foreign'),
            'columns' => $columns,
            'references' => [$referencesTable, ...$referencesColumns],
            'onDelete' => $onDelete === null ? null : ($onDelete instanceof ForeignKeyAction ? $onDelete : ForeignKeyAction::fromChecked($onDelete)),
            'onUpdate' => $onUpdate === null ? null : ($onUpdate instanceof ForeignKeyAction ? $onUpdate : ForeignKeyAction::fromChecked($onUpdate)),
            'deferrable' => $deferrable,
            'initiallyDeferred' => $initiallyDeferred,
        ]];
        return $clone;
    }

    /**
     * Add a table-level CHECK constraint.
     *
     * @param  string  $expression
     * @param  string|null  $name
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function check(string $expression, ?string $name = null): static
    {
        if (trim($expression) === '') {
            throw new \InvalidArgumentException('A CHECK constraint requires a non-empty expression.');
        }

        $clone = clone $this;
        $clone->checks = [...$this->checks, [
            'name' => $name ?? $this->deriveCheckName($expression),
            'expression' => $expression,
        ]];
        return $clone;
    }

    /**
     * Derive the CHECK name from the expression's column references.
     *
     * @param  string  $expression
     * @return string
     */
    private function deriveCheckName(string $expression): string
    {
        $declared = array_map(fn (array $column) => $column['name'], $this->columns);

        // Longest-first so `user_id` matches before `id` inside it.
        usort($declared, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $covered = [];

        foreach ($declared as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $expression) === 1) {
                $covered[] = $name;
            }
        }

        if ($covered === []) {
            // No declared column referenced — positional suffix keeps the
            // name unique per declaration order.
            $covered = [(string) (count($this->checks) + 1)];
        }

        return ConstraintNamer::derive($this->table, $covered, 'check');
    }

    /**
     * The CHECK constraints declared on this blueprint.
     *
     * @return list<array{name: string, expression: string}>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * The foreign-key constraints declared on this blueprint — derived
     * single-column plus explicit composite.
     *
     * @return list<array{name: string, columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}>
     */
    public function getForeignKeys(): array
    {
        $foreignKeys = [];

        foreach ($this->columns as $column) {
            if ($column['foreign'] === null) {
                continue;
            }

            [$table, $referenced] = explode('.', $column['foreign']);

            $foreignKeys[] = [
                'name' => ConstraintNamer::derive($this->table, [$column['name']], 'foreign'),
                'columns' => [$column['name']],
                'references' => [$table, $referenced],
                'onDelete' => $column['onDelete'],
                'onUpdate' => $column['onUpdate'],
                // A flag-derived single-column FK has no deferrability
                // options — those only exist on foreignKey().
                'deferrable' => false,
                'initiallyDeferred' => false,
            ];
        }

        return [...$foreignKeys, ...$this->foreignKeys];
    }

    /**
     * Drop a column (ALTER only).
     *
     * @param  string  $name
     * @return static
     */
    public function dropColumn(string $name): static
    {
        $clone = clone $this;
        $clone->dropColumns = [...$this->dropColumns, $name];
        return $clone;
    }

    /**
     * Drop a foreign-key constraint by its live name (ALTER only).
     *
     * @param  string  $name
     * @return static
     */
    public function dropForeignKey(string $name): static
    {
        $clone = clone $this;
        $clone->dropForeignKeys = [...$this->dropForeignKeys, $name];
        return $clone;
    }

    /**
     * Drop a CHECK constraint by its live name (ALTER only).
     *
     * @param  string  $name
     * @return static
     */
    public function dropCheck(string $name): static
    {
        $clone = clone $this;
        $clone->dropChecks = [...$this->dropChecks, $name];
        return $clone;
    }

    /**
     * The live foreign-key constraint names to drop.
     *
     * @return list<string>
     */
    final public function getDropForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    /**
     * The live CHECK constraint names to drop.
     *
     * @return list<string>
     */
    final public function getDropChecks(): array
    {
        return $this->dropChecks;
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
     * The indexes declared on the blueprint.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Build the desired-state blueprint for a model from its metadata.
     *
     * @param  class-string<Model>  $model
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public static function fromMetadata(string $model): static
    {
        $metadata = MetadataFactory::for($model);
        $tableName = $metadata->tableName;

        if ($tableName === null) {
            throw new \InvalidArgumentException(
                "Model [{$model}] owns no table (no columns of its own); there is "
                . 'nothing to build a blueprint for.'
            );
        }

        $blueprint = new static($tableName);

        // MTI children: the child table holds only the child's own columns
        // plus the derived key — the inherited columns live on the
        // parent's table.
        foreach ($metadata->properties as $mapping) {
            $column = $mapping->column;

            if ($metadata->tableFor($mapping->columnName) !== $tableName) {
                continue; // inherited column — belongs on the parent's table
            }

            $blueprint = $blueprint->column(
                $column->type,
                $mapping->columnName,
                primaryKey: $column->primaryKey,
                autoIncrement: $column->autoIncrement,
                nullable: $column->nullable,
                unique: $column->unique,
                index: $column->index,
                length: $column->length,
                precision: $column->precision,
                default: $column->default,
                foreign: $column->foreign,
                onDelete: $column->onDelete,
                onUpdate: $column->onUpdate,
            );
        }

        // Class-level composite constraints. For an MTI child, only
        // constraints over the child's own columns belong on the child's
        // table.
        $ownColumns = null;

        if ($metadata->parentModel !== null) {
            $ownColumns = array_map(
                fn ($mapping) => $mapping->columnName,
                array_values(array_filter(
                    $metadata->properties,
                    fn ($mapping) => $metadata->tableFor($mapping->columnName) === $tableName,
                )),
            );
        }

        foreach ($metadata->uniques as $unique) {
            if ($ownColumns !== null && array_diff($unique->columns, $ownColumns) !== []) {
                continue; // covers inherited columns — parent table's constraint
            }

            // null name → the blueprint derives the final
            // `{table}_{columns}_unique` name.
            $blueprint = $blueprint->index(
                $unique->name,
                $unique->columns,
                unique: true,
                where: $unique->where,
                nullsNotDistinct: $unique->nullsNotDistinct,
            );
        }

        foreach ($metadata->indexes as $index) {
            if ($ownColumns !== null && array_diff($index->columns, $ownColumns) !== []) {
                continue;
            }

            $blueprint = $blueprint->index($index->name, $index->columns, where: $index->where);
        }

        foreach ($metadata->foreignKeys as $foreignKey) {
            if ($ownColumns !== null && array_diff($foreignKey->columns, $ownColumns) !== []) {
                continue;
            }

            $blueprint = $blueprint->foreignKey(
                $foreignKey->columns,
                $foreignKey->resolvedReferences(),
                $foreignKey->resolvedReferencesColumns(),
                $foreignKey->onDelete,
                $foreignKey->onUpdate,
                $foreignKey->deferrable,
                $foreignKey->initiallyDeferred,
            );
        }

        foreach ($metadata->checks as $check) {
            // A CHECK is table-level — it always belongs on the model's own
            // table, even for an MTI child (there are no column ownership
            // semantics to filter on).
            $blueprint = $blueprint->check($check->expression, $check->name);
        }

        // Class-level #[Morphs] attributes need NO separate pass here: the
        // metadata factory injects the `{name}_type`/`{name}_id` synthetic
        // mappings into $metadata->properties, so the properties loop above
        // already emitted them as ordinary columns — with the exact shapes
        // morphs() produces.

        // MTI children: the factory-emitted FK to the parent table. The
        // shared primary key IS the table link — the child declares no key
        // of its own, so the DDL carries `FOREIGN KEY (id) REFERENCES
        // <parent> (id) ON DELETE CASCADE`.
        if ($metadata->parentModel !== null) {
            $parentMetadata = MetadataFactory::for($metadata->parentModel);
            $parentTable = $parentMetadata->tableName;
            $parentKeys = $parentMetadata->primaryKeys;

            if ($parentTable !== null && count($parentKeys) === 1 && $parentKeys[0]->name !== null) {
                $blueprint = $blueprint->foreignKey(
                    [$parentKeys[0]->name],
                    $parentTable,
                    [$parentKeys[0]->name],
                    ForeignKeyAction::Cascade,
                );
            }
        }

        // Fail fast on duplicate index names WITHIN this blueprint — a
        // collision would compile two CREATE INDEX statements with the same
        // name and the second would fail at the database, far from the
        // declaration that caused it.
        $names = [];

        foreach ($blueprint->getIndexes() as $index) {
            if (isset($names[$index['name']])) {
                throw new \InvalidArgumentException(sprintf(
                    'Model [%s] declares two indexes named [%s] (columns [%s]); '
                    . 'index names must be unique per table. Give the #[Index] '
                    . 'an explicit name, or drop the duplicate constraint.',
                    $model,
                    $index['name'],
                    implode(', ', $index['columns']),
                ));
            }

            $names[$index['name']] = true;
        }

        return $blueprint;
    }
}
