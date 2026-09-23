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
 * Uses the same field vocabulary the ORM's `#[Column]` attribute will use
 * (type, length, nullable, unique, index, foreign, …) so a model's metadata
 * can drive DDL directly. The schema layer is DB-only:
 * it lives under `Database\` and is consumed by
 * {@see \BlueprintAU\Radiant\Database\Connections\SqlConnection::create()}
 * and {@see alter()}, independent of the ORM.
 *
 * **Blueprints are immutable.** Every declaration call (`column()`,
 * `index()`, `foreignKey()`, `check()`, the drops, the renames) returns a
 * NEW blueprint — the original is never modified, so a blueprint can be
 * shared, reused, and chained safely. The copies are cheap: all state is
 * value-type arrays and PHP's copy-on-write means `clone` does not
 * deep-copy them until a write. Chain the results:
 *
 *     $bp = (new Blueprint('users'))
 *         ->id()
 *         ->string('name', 255);
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
 *     onDelete: ForeignKeyAction|null,
 *     onUpdate: ForeignKeyAction|null,
 * }
 */
final class Blueprint
{
    /**
     * The table this blueprint builds — REQUIRED at construction so index
     * names can be derived to their FINAL form (with the table prefix and
     * kind suffix) the moment they are declared. A name on the blueprint
     * is always the name the database will see; the grammar renders it
     * verbatim. Consumers (grammars, differ, connection) read it via
     * {@see getTable()} — no parallel $table parameters anywhere.
     *
     * @var string
     */
    private readonly string $table;

    /**
     * The table this blueprint RENAMES, when the blueprint declares a
     * table rename — the differ turns a declared rename into a real
     * `RenameTable` change instead of a create+drop pair.
     *
     * **A rename is a decision, not a guess.** The differ's heuristic
     * (column-overlap scoring) stays advisory-only; the ONLY path to an
     * executable rename is the host declaring the old name here. Doctrine:
     * "rename advisories are data, never decisions" — the declaration IS
     * the decision.
     *
     * @var string|null
     */
    private string|null $renamedFrom = null;

    /**
     * Column renames declared on this blueprint, in declaration order.
     *
     * Same doctrine as {@see $renamedFrom}: the host declares the mapping
     * (`renameColumn('name', 'full_name')`), the differ emits a real
     * `RenameColumn` change and suppresses the add+drop rename advisory
     * for those columns — a declared rename is never re-flagged as a
     * possible data-losing alter.
     *
     * @var list<array{from: string, to: string}>
     */
    private array $columnRenames = [];

    /**
     * Create a table-bound blueprint.
     *
     * The table is REQUIRED: the blueprint owns the table name (index
     * names derive to their final form at declaration time from it), so
     * every downstream consumer — grammars, the differ, the connection —
     * reads it from {@see getTable()} instead of carrying a parallel
     * `$table` parameter that could disagree with the blueprint.
     *
     * @param string $table The table the blueprint builds.
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * The table this blueprint builds.
     *
     * @return string The table name the blueprint is bound to.
     */
    final public function getTable(): string
    {
        return $this->table;
    }

    /**
     * A copy of this blueprint bound to a DIFFERENT table name.
     *
     * The one legitimate re-binding site is the SQLite rebuild's temp
     * table: the desired shape must render against `users__radiant_new`
     * before the rename makes it `users` again. Everything else on the
     * blueprint (columns, FKs, CHECKs, renames) carries over verbatim —
     * EXCEPT the indexes, which are deliberately dropped: derived index
     * names embed the bound table name, and the rebuild re-creates them
     * from the ORIGINAL blueprint after the rename, so a temp-bound copy
     * must never carry them (a temp name leaking into a derived index
     * name would leave the index named after a table that no longer
     * exists).
     *
     * The blueprint is IMMUTABLE — every entry list is written once at
     * declaration and never mutated afterward — so a plain `clone` is a
     * complete copy: PHP's copy-on-write gives the copy its own arrays
     * the moment any write would touch them, and the historical
     * hand-written deep copy (which guarded against post-creation nested
     * mutation) is dead weight under value semantics.
     *
     * @param string $table The new table name.
     * @return static A copy bound to `$table`, without indexes.
     */
    public function forTable(string $table): static
    {
        // `$table` is readonly, so the rebind goes through the
        // constructor rather than a clone-assign.
        $copy = new static($table);

        // Entries are write-once after declaration (the immutable API
        // guarantees it), so plain array copies are complete copies —
        // PHP's copy-on-write means the nested lists are never shared
        // with a writer.
        $copy->columns = $this->columns;
        $copy->foreignKeys = $this->foreignKeys;
        $copy->checks = $this->checks;
        $copy->columnRenames = $this->columnRenames;
        $copy->renamedFrom = $this->renamedFrom;

        // Indexes are deliberately NOT carried: derived index names embed
        // the bound table name (see the docblock).
        $copy->indexes = [];

        return $copy;
    }

    /**
     * Declare that this blueprint RENAMES an existing table.
     *
     * The blueprint's own table ({@see getTable()}) is the NEW name; the
     * declared value is the OLD (live) name the rename starts from. The
     * differ verifies the declaration against the live schema (old exists,
     * new absent) before emitting an executable `RenameTable` change — a
     * declaration that does not match reality falls through to the usual
     * create/drop handling with the advisory flags, never a wrong rename.
     *
     * @param string $oldTable The live table name being renamed.
     * @return static A new blueprint with the rename declared; the original is unchanged.
     * @throws \InvalidArgumentException When the old name is empty.
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
     * @return string|null The old (live) table name, or null.
     */
    final public function getRenamedFrom(): string|null
    {
        return $this->renamedFrom;
    }

    /**
     * Declare a column rename: the live column `$from` becomes `$to`.
     *
     * The differ emits an executable `RenameColumn` change for each
     * declared rename (after verifying the old column exists live) and
     * suppresses the add+drop rename advisory for those columns — the
     * declaration IS the decision, so the data-losing alter shape never
     * materializes for a declared rename.
     *
     * @param string $from The live column name.
     * @param string $to The new column name.
     * @return static A new blueprint with the rename declared; the original is unchanged.
     * @throws \InvalidArgumentException When either name is empty or the
     *         names are equal.
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
     * Indexes (single or composite), each with its FINAL name.
     *
     * Every name stored here is exactly what the database will see: user-
     * set names pass through verbatim; derived names are built at
     * declaration time via {@see Blueprint::deriveIndexName()} (table
     * prefix + columns + kind suffix). The grammar renders them verbatim —
     * it makes NO naming decisions, so the differ and the collision checks
     * read the same final names.
     *
     * `where` is the partial-index predicate (spliced verbatim — the raw
     * escape hatch, same trust model as an {@see \BlueprintAU\Radiant\Database\Query\Expression}
     * default); `nullsNotDistinct` upgrades a UNIQUE index to `NULLS NOT
     * DISTINCT` semantics (Postgres 15+ — dialects that cannot render it
     * fail fast at compile time).
     *
     * @var list<array{name: string, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
     */
    private array $indexes = [];

    /**
     * Derive the FINAL index name from its kind and columns.
     *
     * Mirrors the framework consensus (Laravel's `createIndexName`, built
     * at blueprint time; SQLAlchemy's naming conventions): the derivation
     * needs the table + columns, both available HERE — not at render time.
     * Shape: `{table}_{columns}_{kind}` for plain indexes, `{table}_{columns}_unique`
     * for uniques (the kind suffix says WHAT the index is, so a unique and
     * a plain index over the same columns can coexist). The shape itself
     * is owned by {@see ConstraintNamer} — one convention, shared with
     * every other constraint kind.
     *
     * @param list<string> $columns The covered columns.
     * @param bool $unique Whether the index is unique.
     * @return string The final index name.
     */
    private function deriveIndexName(array $columns, bool $unique): string
    {
        return ConstraintNamer::derive($this->table, $columns, $unique ? 'unique' : 'index');
    }

    /**
     * Normalize a `foreign` reference to its `table.column` form.
     *
     * Accepted inputs:
     * - `table.column` — passes through (already explicit).
     * - `table` — references that table's primary key; resolved as
     *   `table.id` (the package's PK convention).
     * - a model class-string (contains `\`) — resolves to its table via
     *   the shared {@see \BlueprintAU\Radiant\Attributes\ReferenceResolver},
     *   and the PK column comes from the referenced model's metadata when
     *   it declares a single PK (a composite PK has no single default —
     *   the caller must use the explicit `table.column` form).
     *
     * @param class-string<\BlueprintAU\Radiant\Model>|string $foreign The raw reference.
     * @param string $column The local column name (for the message).
     * @return string The normalized `table.column` reference.
     * @throws \InvalidArgumentException When the reference is malformed or
     *         a model reference cannot resolve.
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
        // fall back to the package's `id` convention for tables the ORM
        // does not own. resolve() already guaranteed a Model when the
        // reference contains a backslash — this branch only runs for
        // model-shaped references, and resolve() guaranteed the class
        // exists AND is a Model; assert it for the type system.
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
     * A single column gets a derived name; multiple columns form a
     * composite. When `$name` is given it is the WHOLE final name (user-set
     * names pass through verbatim — no prefix, no suffix); when omitted the
     * name is derived to its final form here, so anything downstream (the
     * grammar, the differ, the collision checks) reads the same string.
     *
     * @param string|null $name The final index name, or null to derive.
     * @param list<string> $columns The columns to index.
     * @param bool $unique Whether the index is unique.
     * @param string|null $where The partial-index predicate, spliced
     *        verbatim after `WHERE` (e.g. `deleted_at IS NULL`). Postgres
     *        and SQLite render it; MySQL fails fast at compile time.
     * @param bool $nullsNotDistinct Whether a UNIQUE index uses `NULLS NOT
     *        DISTINCT` semantics (Postgres 15+; other dialects fail fast
     *        at compile time). Meaningless on a non-unique index — fails
     *        fast at declaration.
     * @return static A new blueprint with the index declared; the original is unchanged.
     * @throws \InvalidArgumentException When no columns are given, the
     *         `where` predicate is empty, or `nullsNotDistinct` is set on
     *         a non-unique index.
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
     * @param ForeignKeyAction|string|null $onDelete The foreign key ON DELETE
     *        action — validated via {@see ForeignKeyAction::fromChecked()} and
     *        stored as the enum, so no raw string reaches the compiled DDL.
     * @param ForeignKeyAction|string|null $onUpdate The foreign key ON UPDATE
     *        action — validated the same way.
     * @return static A new blueprint with the column appended; the original is unchanged.
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
        ForeignKeyAction|string|null $onDelete = null,
        ForeignKeyAction|string|null $onUpdate = null,
    ): static {
        // Fail fast at DECLARATION, and NORMALIZE the reference to
        // `table.column` so the getter is a pure read. Two accepted forms:
        //   - `table.column` — a plain reference.
        //   - `table` (or a model class-string) — references THAT table's
        //     primary key; a model class-string resolves to its table via
        //     the shared {@see ReferenceResolver} (the same convention the
        //     class-level #[ForeignKey] uses), and the PK column name is
        //     taken from the referenced model's primary key when one is
        //     declared (a table with no single PK must use the explicit
        //     `table.column` form).
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
            'default' => $default,
            'foreign' => $foreign,
            'onDelete' => $onDelete === null ? null : ($onDelete instanceof ForeignKeyAction ? $onDelete : ForeignKeyAction::fromChecked($onDelete)),
            'onUpdate' => $onUpdate === null ? null : ($onUpdate instanceof ForeignKeyAction ? $onUpdate : ForeignKeyAction::fromChecked($onUpdate)),
        ]];

        // A flagged plain index derives its FINAL name at DECLARATION time
        // (`unique: true` rides the column's inline UNIQUE constraint, so
        // no separate index). Getters stay pure reads.
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
     * @param string $name The column name.
     * @param ColumnType $type The column type (default {@see ColumnType::BigInt}).
     * @param bool $autoIncrement Whether the key auto-increments.
     * @return static A new blueprint with the column appended; the original is unchanged.
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
     * @return static A new blueprint with the column appended; the original is unchanged.
     */
    public function string(string $name, int $length): static
    {
        return $this->column(ColumnType::String, $name, length: $length);
    }

    /**
     * Add a nullable datetime column.
     *
     * @param string $name The column name.
     * @return static A new blueprint with the column appended; the original is unchanged.
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
     * @param ForeignKeyAction|string|null $onDelete The ON DELETE action.
     * @param ForeignKeyAction|string|null $onUpdate The ON UPDATE action.
     * @return static A new blueprint with the column appended; the original is unchanged.
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
     * The ONE emission path for morph columns — the `#[Morphs]` attribute
     * folds into this exact call sequence via `Blueprint::fromMetadata()`,
     * so a metadata-driven table and a hand-built one always produce
     * identical DDL.
     *
     * The pair carries NO foreign key: `{name}_type` names the target
     * table at runtime (the related model's class-string), so no static
     * FK can express the reference — the integrity is the relation's job,
     * not the schema's. `{name}_type` is a 255-char string (room for a
     * full class-string); `{name}_id` is a bigint (the conventional PK
     * type of the target tables).
     *
     * @param string $name The morph alias prefix — emits `{name}_type` and
     *        `{name}_id`.
     * @param bool $nullable Whether both columns allow null (an optional
     *        polymorphic relation).
     * @return static A new blueprint with both columns appended; the original is unchanged.
     * @throws \InvalidArgumentException When `$name` is empty.
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
     * Foreign-key constraints — single-column via `foreignId()` are inline;
     * this holds table-level (composite) constraints.
     *
     * Every entry carries its FINAL `name` (derived at declaration via
     * {@see ConstraintNamer} — `{table}_{columns}_foreign`), the same
     * doctrine as indexes and checks: the name is the drop handle, final
     * before render, so the connection and the differ read the same
     * string the database will see.
     *
     * `deferrable`/`initiallyDeferred` are Postgres-only options (MySQL and
     * SQLite fail fast at compile time when set).
     *
     * @var list<array{name: string, columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}>
     */
    private array $foreignKeys = [];

    /**
     * Table-level CHECK constraints, in declaration order.
     *
     * A CHECK is portable across all three dialects. The expression is
     * spliced verbatim — the raw escape hatch, same trust model as an
     * {@see \BlueprintAU\Radiant\Database\Query\Expression} default. The
     * NAME follows the same rule as {@see index()}: when given it is the
     * WHOLE final name (user-set names pass through verbatim); when
     * omitted it is DERIVED (`{table}_{columns}_check`) — so every CHECK
     * carries a final name and is diffable by name like every other
     * constraint.
     *
     * @var list<array{name: string, expression: string}>
     */
    private array $checks = [];

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
     * @param ForeignKeyAction|string|null $onDelete The ON DELETE action.
     * @param ForeignKeyAction|string|null $onUpdate The ON UPDATE action.
     * @param bool $deferrable Whether the constraint is DEFERRABLE
     *        (Postgres only — other dialects fail fast at compile time).
     * @param bool $initiallyDeferred Whether the constraint starts
     *        INITIALLY DEFERRED (implies `$deferrable`; fails fast when
     *        set without it).
     * @return static A new blueprint with the constraint declared; the original is unchanged.
     * @throws \InvalidArgumentException When the column/reference arity
     *         mismatches, either list is empty, or `initiallyDeferred` is
     *         set without `deferrable`.
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
     * The expression is spliced verbatim after `CHECK` — the raw escape
     * hatch for dialect functions and predicates (e.g. `price >= 0`,
     * `status IN ('draft', 'published')`).
     *
     * The NAME follows the same rule as {@see index()}: when given it is
     * the WHOLE final name (user-set names pass through verbatim — no
     * prefix, no suffix); when omitted it is DERIVED to its final form
     * here — `{table}_{columns}_check` (the covered columns joined with
     * underscores) — so anything downstream (the grammar, the differ)
     * reads the same string, and the constraint is diffable by name like
     * every other named constraint.
     *
     * @param string $expression The CHECK predicate, spliced verbatim.
     * @param string|null $name The final constraint name, or null to
     *        derive `{table}_{columns}_check`.
     * @return static A new blueprint with the constraint declared; the original is unchanged.
     * @throws \InvalidArgumentException When the expression is empty.
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
     * Derive the FINAL CHECK name from the expression's column
     * references.
     *
     * Mirrors {@see deriveIndexName()}: the derivation needs the table +
     * the covered columns, both available HERE. The columns are the
     * declared column names appearing in the expression (word-boundary
     * match, longest-first so `user_id` wins over `id`); a CHECK over no
     * declared column (e.g. `1 = 1`) falls back to a positional suffix.
     * The shape is owned by {@see ConstraintNamer}.
     *
     * @param string $expression The CHECK predicate.
     * @return string The final CHECK name.
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
     * The CHECK constraints — every entry carries a FINAL name (derived
     * at declaration time when omitted); pure read.
     *
     * @return list<array{name: string, expression: string}>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * Foreign-key constraints — derived single-column plus explicit composite.
     *
     * Single-column FKs come from columns declared with `foreign` (the
     * `table.column` reference was VALIDATED at {@see column()} time);
     * explicit {@see foreignKey()} declarations (single or composite) are
     * appended after. Each entry's `references` is `[table, ...columns]`.
     * Every entry carries the full constraint shape INCLUDING its final
     * `name` (derived via {@see ConstraintNamer} — the same handle the
     * SQLite inspector derives when reading live constraints back).
     * Derived single-column FKs always render `deferrable: false`/
     * `initiallyDeferred: false` (a column-level `foreign:` flag has no
     * deferrability knobs; use the class-level `#[ForeignKey(deferrable: ...)]`
     * for those). No other derivation happens here — pure read.
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
                // declaration site — the option only exists on the
                // class-level #[ForeignKey] attribute / foreignKey().
                'deferrable' => false,
                'initiallyDeferred' => false,
            ];
        }

        return [...$foreignKeys, ...$this->foreignKeys];
    }

    /**
     * Drop a column (ALTER only).
     *
     * @param string $name The column name.
     * @return static A new blueprint with the drop declared; the original is unchanged.
     */
    public function dropColumn(string $name): static
    {
        $clone = clone $this;
        $clone->dropColumns = [...$this->dropColumns, $name];
        return $clone;
    }

    /**
     * Drop a foreign-key constraint by its LIVE name (ALTER only).
     *
     * The name is the drop handle the inspector captured — the differ
     * fills it from the live schema, so the drop addresses the constraint
     * that actually exists.
     *
     * @param string $name The live constraint name.
     * @return static A new blueprint with the drop declared; the original is unchanged.
     */
    public function dropForeignKey(string $name): static
    {
        $clone = clone $this;
        $clone->dropForeignKeys = [...$this->dropForeignKeys, $name];
        return $clone;
    }

    /**
     * Drop a CHECK constraint by its LIVE name (ALTER only).
     *
     * @param string $name The live constraint name.
     * @return static A new blueprint with the drop declared; the original is unchanged.
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
     * The indexes — every index declared on the blueprint, each with its
     * FINAL name (derived at declaration time from the bound table, or
     * user-set verbatim). Pure read: no derivation happens here.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Build the desired-state Blueprint for a model from its cached
     * metadata — the one place ORM metadata and DDL meet.
     *
     * A pure mapping, no I/O: every `#[Column]` (whichever ancestor
     * declared it — the metadata is the MERGED view) folds into a
     * `column()` call; `#[Column]` flags (unique/index/foreign) ride the
     * same call; the class-level `#[Unique]` / `#[ForeignKey]` /
     * `#[Index]` attributes become explicit `index()` /
     * `foreignKey()` declarations (with the duplicate-declaration rule
     * already enforced at metadata build, so a flag and an attribute can
     * never double-declare here).
     *
     * @param class-string<Model> $model The model class.
     * @return static The desired-state blueprint.
     * @throws \InvalidArgumentException When the model resolves no table
     *         (a column-less model has nothing to sync).
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

        // MTI children: the child table holds ONLY the child's own columns
        // plus the derived key — the inherited columns live on the parent's
        // table (that is what multi-table inheritance means). The partition
        // map ({@see ClassMetadata::tableFor()}) resolves each merged
        // mapping to its owning table; a non-MTI model resolves every
        // column to its own table, so the filter is a no-op there.
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
                default: $column->default,
                foreign: $column->foreign,
                onDelete: $column->onDelete,
                onUpdate: $column->onUpdate,
            );
        }

        // Class-level composite constraints (the single-column flag cases
        // already rode the column() calls above). For an MTI child, only
        // constraints over the CHILD'S OWN columns belong on the child's
        // table — a constraint covering an inherited column travels with
        // the parent's table (its flag is already there).
        //
        // Unique index naming: a hardcoded constant would collide — two
        // `#[Unique]` attributes would emit two CREATE UNIQUE INDEX
        // statements with the same name and the second would fail at the
        // DB. The default derives from the covered columns with a `_unique`
        // suffix (`{columns}_unique`, rendered `{table}_{name}_unique` by
        // the grammar): the suffix says WHAT the index is, it cannot
        // collide with a #[Index] over the same columns (which
        // derives `{columns}` bare), and an explicit #[Unique(name: ...)]
        // always wins. The duplicate-name guard at the bottom of this
        // method catches any remaining collision (e.g. genuinely duplicated
        // constraints) at blueprint-build time instead of at DDL time.
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
            // `{table}_{columns}_unique` name; a user-set name passes
            // through verbatim (it IS the whole name).
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
        // morphs() produces (string 255 + bigint, same nullability). That
        // IS the one-emission-path guarantee: a metadata-driven table and a
        // hand-built `morphs()` call compile identical DDL.

        // MTI children: the factory-emitted FK to the parent table. The
        // shared primary key IS the table link — the child declares no key
        // of its own (the factory derives it with autoIncrement: false), so
        // the DDL carries `FOREIGN KEY (id) REFERENCES <parent> (id) ON
        // DELETE CASCADE`. No user declaration exists to double-declare it.
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
        // declaration that caused it. (Column-derived names collide only
        // when the covered columns are identical — a genuinely duplicated
        // constraint, which SHOULD fail here rather than at DDL time.)
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
