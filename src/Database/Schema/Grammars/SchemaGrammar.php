<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

use BlueprintAU\Radiant\Database\Concerns\ConcatenatesStatements;
use BlueprintAU\Radiant\Database\Concerns\QuotesLiterals;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;

/**
 * Compiles schema definitions into dialect DDL.
 *
 * The DB-only counterpart to the query {@see \BlueprintAU\Radiant\Database\Grammars\Grammar}:
 * it turns a {@see Blueprint} into `CREATE TABLE` / `ALTER TABLE` /
 * `DROP TABLE` statements. Subclasses provide the dialect specifics —
 * identifier quoting via {@see wrap()}, and the type mapping via
 * {@see type()}.
 *
 * **Only the roots are public** — {@see compileCreate()},
 * {@see compileAlter()}, {@see compileDrop()}, and
 * {@see compileIndexes()} — matching the query Grammar's "only the roots
 * are public" convention. Every leaf is protected.
 *
 * **Fail-fast dialect gating.** A feature the active dialect cannot express
 * throws {@see UnsupportedFeatureException} — never silently ignored. The
 * base Grammar throws for dialect-gated features (e.g. dropping a column on
 * SQLite); dialects override only what they support.
 *
 * @phpstan-import-type ColumnShape from \BlueprintAU\Radiant\Database\Schema\Blueprint
 */
abstract class SchemaGrammar
{
    use QuotesLiterals;
    use ConcatenatesStatements;

    /**
     * Wrap an identifier in the dialect's quote character.
     *
     * @param string $value The identifier to quote.
     * @return string The quoted identifier.
     */
    abstract protected function wrap(string $value): string;

    /**
     * Map a logical column type to the dialect's native type.
     *
     * PUBLIC (not protected) because it is half of the content-drift
     * contract: the inspectors compare the live native type text against
     * the declared type rendered through THIS mapping — the round-trip
     * guarantee (what the grammar renders into DDL is exactly what the
     * inspector reads back) needs the accessor, and the accessor IS the
     * mapping. Pure read: no state, no I/O.
     *
     * @param ColumnType $type The logical column type.
     * @param int|null $length The column length, if any.
     * @return string The dialect's native type.
     */
    abstract public function type(ColumnType $type, ?int $length = null): string;

    /**
     * Compile a `CREATE TABLE` statement.
     *
     * The table comes from the blueprint itself ({@see Blueprint::getTable()})
     * — one source of truth, no parallel parameter that could disagree.
     *
     * @param Blueprint $blueprint The table and columns to create.
     * @return string The compiled SQL.
     */
    final public function compileCreate(Blueprint $blueprint): string
    {
        return 'CREATE TABLE ' . $this->wrap($blueprint->getTable()) . ' (' . $this->compileTableBody($blueprint) . ')';
    }

    /**
     * Compile the parenthesized BODY of a `CREATE TABLE` — the column
     * definitions, table-level constraints, and CHECKs, without the
     * `CREATE TABLE name` wrapper.
     *
     * The extraction exists for the SQLite rebuild: the temp table is the
     * SAME desired shape rendered against a different name, and
     * {@see Blueprint::forTable()} re-binds a blueprint — so the rebuild
     * compiles `CREATE TABLE temp (body)` from the re-bound copy without
     * duplicating the body logic here.
     *
     * @param Blueprint $blueprint The table and columns to create.
     * @return string The body (column definitions + constraints).
     */
    final public function compileTableBody(Blueprint $blueprint): string
    {
        $columns = $blueprint->getColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot create a table with no columns.');
        }

        // A composite primary key is declared table-level, not per-column.
        $primaryKeys = array_values(array_filter(
            $columns,
            fn (array $column) => $column['primaryKey'] === true,
        ));
        $composite = count($primaryKeys) > 1;

        $definitions = array_map(
            fn (array $column) => $this->compileColumnDefinition($column, $composite),
            $columns,
        );

        if ($composite) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map(
                fn (array $column) => $this->wrap($column['name']),
                $primaryKeys,
            )) . ')';
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $definitions[] = $this->compileForeignKeyConstraint($foreignKey);
        }

        foreach ($blueprint->getChecks() as $check) {
            $definitions[] = $this->compileCheckConstraint($check);
        }

        return implode(', ', $definitions);
    }

    /**
     * Compile a table-level CHECK constraint.
     *
     * CHECK is standard SQL and portable across MySQL, Postgres, and
     * SQLite — the base renders it and no dialect override is needed. A
     * named constraint renders `CONSTRAINT name CHECK (expr)`; unnamed
     * constraints render the bare `CHECK (expr)` and the dialect assigns
     * its own name.
     *
     * @param array{name: string|null, expression: string} $check The
     *        constraint — the name is FINAL (derived at declaration time).
     * @return string The compiled constraint.
     */
    protected function compileCheckConstraint(array $check): string
    {
        if ($check['name'] !== null) {
            $this->assertValidIdentifier($check['name']);
            return 'CONSTRAINT ' . $this->wrap($check['name']) . ' CHECK (' . $check['expression'] . ')';
        }

        return 'CHECK (' . $check['expression'] . ')';
    }

    /**
     * Compile a table-level foreign-key constraint.
     *
     * @param array{columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool} $foreignKey
     *        The constraint — the first references element is the table, the
     *        rest are the referenced columns.
     * @return string The compiled constraint.
     */
    protected function compileForeignKeyConstraint(array $foreignKey): string
    {
        $table = array_shift($foreignKey['references']);
        if ($table === null) {
            throw new \InvalidArgumentException('A foreign key constraint requires a referenced table.');
        }

        // Statement assembly: the constraint body plus optional clauses —
        // the concatenate() join (absent clause = '' segment, dropped),
        // NOT a list join. Column lists inside the parens stay list-joins.
        return $this->concatenate([
            'FOREIGN KEY (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $foreignKey['columns'])) . ')',
            'REFERENCES ' . $this->wrap($table) . ' (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $foreignKey['references'])) . ')',
            $foreignKey['onDelete'] === null ? '' : 'ON DELETE ' . $foreignKey['onDelete']->value,
            $foreignKey['onUpdate'] === null ? '' : 'ON UPDATE ' . $foreignKey['onUpdate']->value,
            $foreignKey['initiallyDeferred'] || $foreignKey['deferrable']
                ? $this->compileDeferrableClause($foreignKey['initiallyDeferred'])
                : '',
        ]);
    }

    /**
     * The dialect's `DEFERRABLE` clause for a foreign key.
     *
     * The BASE DIALECT cannot defer constraints — the fail-fast lives
     * here so a dialect must OPT IN; the option degrades to a loud error
     * at compile time instead of silently weaker enforcement. This hook
     * OWNS the clause TEXT (the same split as {@see autoIncrement()}):
     * the base decides WHEN the clause is needed (the constraint declares
     * deferrability), the dialect supplies WHAT it renders — so the base
     * grammar contains no dialect SQL.
     *
     * @param bool $initiallyDeferred Whether the constraint starts
     *        INITIALLY DEFERRED (the dialect decides the rendering of the
     *        two levels).
     * @return string The clause, rendered after the constraint body.
     * @throws UnsupportedFeatureException Always in the base dialect.
     */
    protected function compileDeferrableClause(bool $initiallyDeferred): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support DEFERRABLE foreign keys.'
        );
    }

    /**
     * Compile an `ALTER TABLE ... ADD COLUMN` statement — one public root
     * per SQL statement (the compile-only surface rule): the caller picks
     * the compiler for the statement it wants, no operation-enum dispatch
     * in between.
     *
     * @param Blueprint $blueprint The table and columns to add.
     * @return string The compiled SQL.
     * @throws \InvalidArgumentException When the blueprint declares no
     *         columns to add.
     */
    final public function compileAddColumns(Blueprint $blueprint): string
    {
        return $this->compileAddColumn($blueprint);
    }

    /**
     * Compile an `ALTER TABLE ... DROP COLUMN` statement — one public root
     * per SQL statement (the compile-only surface rule).
     *
     * @param Blueprint $blueprint The table and columns to drop.
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException When the dialect cannot drop
     *         columns (the base dialect; MySQL and Postgres override).
     */
    final public function compileDropColumns(Blueprint $blueprint): string
    {
        return $this->compileDropColumn($blueprint);
    }

    /**
     * Compile a `DROP TABLE` statement.
     *
     * @param string $table The table name.
     * @return string The compiled SQL.
     */
    final public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->wrap($table);
    }

    /**
     * Compile an `ALTER TABLE ... RENAME TO` statement.
     *
     * Portable across MySQL, Postgres, and SQLite — the base renders it
     * and no dialect override is needed. The rename moves the table and
     * every row with it; indexes and constraints travel with the table.
     *
     * @param string $from The live table name.
     * @param string $to The new table name.
     * @return string The compiled SQL.
     */
    final public function compileRenameTable(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($from) . ' RENAME TO ' . $this->wrap($to);
    }

    /**
     * Compile an `ALTER TABLE ... RENAME COLUMN` statement.
     *
     * MySQL 8.0+, Postgres, and SQLite 3.25+ all support `RENAME COLUMN`
     * with the same syntax — the base renders it and no dialect override
     * is needed. The column's data travels with the rename.
     *
     * @param string $table The table the column is on.
     * @param string $from The live column name.
     * @param string $to The new column name.
     * @return string The compiled SQL.
     */
    final public function compileRenameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($table) . ' RENAME COLUMN '
            . $this->wrap($from) . ' TO ' . $this->wrap($to);
    }

    /**
     * Compile an `INSERT INTO ... SELECT` data-copy statement — the one
     * new compile the SQLite table rebuild needs.
     *
     * The rebuild sequence (create temp → copy → drop old → rename) is
     * ORCHESTRATION owned by the connection; the grammar's only job in it
     * is this pure statement compile. The column list comes from the
     * inspector's LIVE columns at runtime (passed in as a parameter — the
     * grammar never touches the connection), so the copy projects exactly
     * the columns that exist on both sides.
     *
     * @param string $from The source table (the live table).
     * @param string $to The destination table (the temp table).
     * @param list<string> $columns The columns to copy — must exist on
     *        BOTH tables (the live column names).
     * @return string The compiled SQL.
     * @throws \InvalidArgumentException When no columns are given.
     */
    final public function compileCopyTable(string $from, string $to, array $columns): string
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('A table copy requires at least one column.');
        }

        $wrapped = implode(', ', array_map(fn (string $column) => $this->wrap($column), $columns));

        return 'INSERT INTO ' . $this->wrap($to) . ' (' . $wrapped . ') SELECT ' . $wrapped . ' FROM ' . $this->wrap($from);
    }

    /**
     * Compile an `ALTER TABLE ... MODIFY/ALTER COLUMN` statement — the
     * in-place content-drift form.
     *
     * MySQL renders `MODIFY`; Postgres needs MULTIPLE statements (TYPE /
     * SET NOT NULL / SET DEFAULT are separate clauses), so it overrides
     * to return a list. SQLite has NO in-place form at all — it inherits
     * this base throw and the connection routes the change through the
     * table rebuild instead (the same gate pattern as
     * {@see compileDropColumn()}).
     *
     * @param Blueprint $blueprint The table-bound blueprint carrying the
     *        DESIRED column shapes (the modified columns are the ones the
     *        change targets).
     * @return list<string> The compiled statements, in execution order.
     * @throws UnsupportedFeatureException Always in the base dialect
     *         (SQLite).
     */
    public function compileModifyColumn(Blueprint $blueprint): array
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support modifying columns in place; the change requires a table rebuild.'
        );
    }

    /**
     * Compile an `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY`
     * statement — the in-place FK-add form.
     *
     * MySQL and Postgres support adding a named FK constraint to an
     * existing table; SQLite has no in-place constraint ALTER and
     * inherits this base throw (the connection routes the change through
     * the table rebuild instead). The constraint NAME is required — the
     * drop handle must exist before the constraint is added, or a later
     * drop could not address it.
     *
     * @param string $table The table to attach the constraint to.
     * @param array{columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool} $foreignKey
     *        The constraint shape (the first references element is the
     *        referenced table).
     * @param string $name The constraint name (the drop handle).
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException Always in the base dialect
     *         (SQLite).
     */
    public function compileAddForeignKey(string $table, array $foreignKey, string $name): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support adding a foreign key constraint in place; the change requires a table rebuild.'
        );
    }

    /**
     * Compile the `ALTER TABLE ... DROP FOREIGN KEY/CONSTRAINT` statement
     * — the in-place FK-drop form.
     *
     * MySQL uses `DROP FOREIGN KEY name`; Postgres uses `DROP CONSTRAINT
     * name` — dialects override. SQLite inherits the base throw (rebuild
     * path).
     *
     * @param string $table The table the constraint is on.
     * @param string $name The live constraint name (the drop handle).
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException Always in the base dialect
     *         (SQLite).
     */
    public function compileDropForeignKey(string $table, string $name): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support dropping a foreign key constraint in place; the change requires a table rebuild.'
        );
    }

    /**
     * Compile an `ALTER TABLE ... ADD CONSTRAINT ... CHECK` statement —
     * the in-place CHECK-add form.
     *
     * MySQL and Postgres support it; SQLite inherits the base throw
     * (rebuild path).
     *
     * @param string $table The table to attach the constraint to.
     * @param string $name The constraint name (the drop handle).
     * @param string $expression The CHECK predicate, spliced verbatim.
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException Always in the base dialect
     *         (SQLite).
     */
    public function compileAddCheck(string $table, string $name, string $expression): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support adding a CHECK constraint in place; the change requires a table rebuild.'
        );
    }

    /**
     * Compile an `ALTER TABLE ... DROP CONSTRAINT` statement — the
     * in-place CHECK-drop form.
     *
     * Postgres uses `DROP CONSTRAINT name`; MySQL has no named-CHECK drop
     * (CHECK constraints are not first-class there) so it inherits the
     * base throw; SQLite inherits it too (rebuild path).
     *
     * @param string $table The table the constraint is on.
     * @param string $name The live constraint name (the drop handle).
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException Always in the base dialect.
     */
    public function compileDropCheck(string $table, string $name): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support dropping a CHECK constraint in place; the change requires a table rebuild.'
        );
    }

    /**
     * Compile a `DROP INDEX` statement for an existing index name.
     *
     * The differ's `AlterIndexes` path needs to drop a drifted live index
     * before re-creating it — and the drop SYNTAX is dialect-split: MySQL
     * drops indexes relative to their table (`ALTER TABLE … DROP INDEX`),
     * while Postgres and SQLite drop by name alone (`DROP INDEX …`), so
     * the base leaves the ABSTRACT shape to each dialect — same policy as
     * {@see assertValidIdentifier()}: every dialect DECIDES its syntax
     * explicitly, never a silent no-op base.
     *
     * @param string $name The index name (already final — it was built at
     *        declaration time and is the name the database sees).
     * @param string $table The table the index is on (MySQL needs it).
     * @return string The compiled SQL.
     */
    abstract public function compileDropIndex(string $name, string $table): string;

    /**
     * Compile the `CREATE INDEX` statements for the blueprint's indexes.
     *
     * Each {@see Blueprint::getIndexes()} entry — derived single-column
     * (from `column(index: true)`, unique ones already inline) or explicit
     * composite — becomes its own `CREATE [UNIQUE] INDEX name ON table
     * (c1, c2, …)` statement. `CREATE INDEX name ON table (col)` is portable
     * across MySQL, SQLite, and Postgres, so the base implementation needs
     * no dialect override.
     *
     * Dialect-gated options ride CLAUSE hooks so the dialect-specific SQL
     * lives with the dialect that renders it (the same split as
     * {@see autoIncrement()}): the base decides WHEN an option needs a
     * clause and calls the hook; the hook returns the clause text or — in
     * the base implementation — throws, so a dialect must OPT IN by
     * overriding. `$index['where']` (partial index) goes through
     * {@see compilePartialIndexClause()}, the NULLS semantics through
     * {@see compileNullsNotDistinctClause()} — tiered: the DEFAULT
     * unique-index semantics compile with no clause on every dialect,
     * while the `nullsNotDistinct` UPGRADE throws in the base and renders
     * explicitly (both directions) on Postgres.
     *
     * @param Blueprint $blueprint The blueprint.
     * @return list<string> One `CREATE INDEX` statement per index.
     */
    final public function compileIndexes(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();

        return array_map(
            function (array $index) use ($table): string {
                // Names on the blueprint are FINAL (built at declaration
                // time — user-set names verbatim, derived names with the
                // table prefix and kind suffix). The grammar renders them
                // as-is; its only job is quoting + dialect validation.
                $this->assertValidIdentifier($index['name']);

                $sql = ($index['unique'] ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
                    . $this->wrap($index['name'])
                    . ' ON ' . $this->wrap($table)
                    . ' (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $index['columns'])) . ')';

                // The option clauses are optional segments of the one
                // statement — statement assembly, not a list join. The
                // NULLS clause rides EVERY unique index (the base carries
                // the default semantics implicitly as ''; Postgres pins
                // them explicitly); a plain index renders neither.
                return $this->concatenate([
                    $sql,
                    $index['unique']
                        ? $this->compileNullsNotDistinctClause($index['nullsNotDistinct'])
                        : '',
                    $index['where'] === null ? '' : $this->compilePartialIndexClause($index['where']),
                ]);
            },
            $blueprint->getIndexes(),
        );
    }

    /**
     * The dialect's `NULLS [NOT] DISTINCT` clause for a UNIQUE index.
     *
     * Tiering matters here: the DEFAULT unique-index semantics (NULLS
     * DISTINCT — multiple NULLs allowed) are universal SQL every dialect
     * carries implicitly, so the base returns `''` for `$nullsNotDistinct
     * === false` and the statement compiles with no clause on MySQL and
     * SQLite. Only the UPGRADE (`NULLS NOT DISTINCT`, Postgres 15+) is
     * dialect knowledge — the base throws for `$nullsNotDistinct ===
     * true` so the option fails fast instead of silently weakening the
     * constraint. Dialects that HAVE the clause (Postgres) override to
     * render it EXPLICITLY in both directions — `NULLS DISTINCT` pinned
     * in the DDL makes dumps self-documenting and immune to a future
     * default flip. Owns the clause TEXT, same split as
     * {@see compileDeferrableClause()}.
     *
     * @param bool $nullsNotDistinct The declared option value.
     * @return string The clause, rendered after the column list ('' when
     *         the dialect carries the default semantics implicitly).
     * @throws UnsupportedFeatureException When `$nullsNotDistinct` is
     *         true and the dialect has no `NULLS NOT DISTINCT`.
     */
    protected function compileNullsNotDistinctClause(bool $nullsNotDistinct): string
    {
        if ($nullsNotDistinct) {
            throw new UnsupportedFeatureException(
                'This dialect does not support NULLS NOT DISTINCT on a unique index.'
            );
        }

        return '';
    }

    /**
     * The dialect's partial (filtered) index clause for the given
     * predicate.
     *
     * The base dialect cannot — Postgres and SQLite support `CREATE INDEX
     * ... WHERE`, MySQL does not. Same clause-hook split as
     * {@see compileNullsNotDistinctClause()}; the predicate is spliced
     * verbatim (the raw escape hatch, same trust model as an Expression
     * default) — the dialect formats the clause AROUND it.
     *
     * @param string $predicate The declared predicate (never empty —
     *        validated at declaration time).
     * @return string The clause, rendered after the column list (and any
     *         NULLS NOT DISTINCT clause).
     * @throws UnsupportedFeatureException Always in the base dialect.
     */
    protected function compilePartialIndexClause(string $predicate): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support partial (filtered) indexes.'
        );
    }

    /**
     * Assert an identifier is valid for this dialect.
     *
     * Doctrine's pattern: the dialect VALIDATES the (already-final) name at
     * compile time and throws — it never silently mutates a name, because a
     * truncated name is not stable across syncs and would break the differ.
     *
     * ABSTRACT on purpose: every dialect must DECIDE its identifier policy
     * explicitly — a silent no-op base would let a dialect forget the cap
     * it actually has (MySQL's 64 chars erroring at the server, far from
     * the declaration). SQLite/Postgres have effectively no practical cap;
     * their overrides return without throwing.
     *
     * @param string $name The final identifier (index name).
     * @return void
     * @throws \InvalidArgumentException When the identifier violates a
     *         dialect limit.
     */
    abstract public function assertValidIdentifier(string $name): void;

    /**
     * Compile an `ALTER TABLE ... ADD COLUMN` statement.
     *
     * @param Blueprint $blueprint The table and columns to add.
     * @return string The compiled SQL.
     */
    protected function compileAddColumn(Blueprint $blueprint): string
    {
        $table = $blueprint->getTable();
        $columns = $blueprint->getColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot add columns with no columns defined.');
        }

        return 'ALTER TABLE ' . $this->wrap($table) . ' ADD COLUMN ' . implode(', ADD COLUMN ', array_map(
            fn (array $column) => $this->compileColumnDefinition($column),
            $columns,
        ));
    }

    /**
     * Compile an `ALTER TABLE ... DROP COLUMN` statement.
     *
     * SQLite cannot drop columns before 3.35 (and even then only with
     * restrictions), so the base throws; dialects that support it override.
     *
     * @param Blueprint $blueprint The table and columns to drop.
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException Always — the base dialect cannot
     *         drop columns.
     */
    protected function compileDropColumn(Blueprint $blueprint): string
    {
        throw new UnsupportedFeatureException('This dialect does not support dropping columns.');
    }

    /**
     * Compile a single column definition.
     *
     * @param ColumnShape $column The column definition.
     * @param bool $composite Whether the column is part of a composite
     *        primary key (declared table-level, so no inline PRIMARY KEY).
     * @return string The compiled column SQL.
     */
    protected function compileColumnDefinition(array $column, bool $composite = false): string
    {
        $name = $this->wrap($column['name']);
        $type = $this->type($column['type'], $column['length']);

        // A composite PK has no generated id in the ORM's contract (the
        // caller assigns every key part — insertGetId is a single-column
        // concept), so the auto-increment clause is suppressed on its
        // member columns. On SQLite it would be outright invalid:
        // AUTOINCREMENT is only legal on a single-column INTEGER PRIMARY
        // KEY. Auto-increment placement is otherwise dialect-dependent:
        // MySQL/Postgres render it before PRIMARY KEY (`AUTO_INCREMENT
        // PRIMARY KEY`, `GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`),
        // while SQLite requires it after (`INTEGER PRIMARY KEY
        // AUTOINCREMENT`).
        $autoIncrement = $column['autoIncrement'] === true && !$composite;
        $beforeKey = $autoIncrement && $this->autoIncrementBeforePrimaryKey();
        $afterKey = $autoIncrement && !$this->autoIncrementBeforePrimaryKey();

        // Statement assembly: the optional clauses are concatenate()
        // segments — NOT NULL / DEFAULT / UNIQUE / the auto-increment and
        // PRIMARY KEY clauses each may be absent. No list items here.
        return $this->concatenate([
            $name,
            $type,
            $column['nullable'] !== true ? 'NOT NULL' : '',
            $column['default'] !== null ? 'DEFAULT ' . $this->compileDefault($column['default']) : '',
            $column['unique'] === true ? 'UNIQUE' : '',
            $beforeKey ? $this->autoIncrement() : '',
            // A single-column PK is declared inline; a composite PK is
            // declared table-level (see compileCreate), so a column that is
            // part of a composite PK must not also get an inline PRIMARY KEY.
            $column['primaryKey'] === true && !$composite ? 'PRIMARY KEY' : '',
            $afterKey ? $this->autoIncrement() : '',
        ]);
    }

    /**
     * Compile a column default.
     *
     * An {@see \BlueprintAU\Radiant\Database\Query\Expression} default (e.g.
     * `CURRENT_TIMESTAMP`, `now()`) is spliced verbatim — the raw escape
     * hatch for dialect functions, matching the query Grammar's
     * {@see \BlueprintAU\Radiant\Database\Grammars\Grammar::parameter()}.
     * Anything else must be a scalar and is quoted as a literal.
     *
     * @param mixed $default The column default.
     * @return string The compiled default.
     * @throws \InvalidArgumentException When the default is neither an
     *         Expression nor a scalar.
     */
    protected function compileDefault(mixed $default): string
    {
        if ($default instanceof \BlueprintAU\Radiant\Database\Query\Expression) {
            return $default->value;
        }
        if (is_scalar($default) || $default === null) {
            return $this->quoteLiteral($default);
        }
        throw new \InvalidArgumentException(
            'A column default must be a scalar or an Expression; got ' . get_debug_type($default) . '.'
        );
    }

    /**
     * The dialect's auto-increment clause.
     *
     * @return string The clause (e.g. `AUTOINCREMENT`, `AUTO_INCREMENT`,
     *         `GENERATED BY DEFAULT AS IDENTITY`).
     */
    abstract protected function autoIncrement(): string;

    /**
     * Whether the auto-increment clause renders before `PRIMARY KEY`.
     *
     * MySQL and Postgres render `AUTO_INCREMENT PRIMARY KEY` /
     * `GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`; SQLite requires
     * `INTEGER PRIMARY KEY AUTOINCREMENT`, so it overrides this to false.
     *
     * @return bool True when the clause precedes PRIMARY KEY.
     */
    protected function autoIncrementBeforePrimaryKey(): bool
    {
        return true;
    }

    /**
     * Require a length for a string column.
     *
     * A string without a length is a silent default that may surprise, so
     * the fail-fast philosophy requires it explicitly.
     *
     * @param int|null $length The column length.
     * @return int The length.
     * @throws \InvalidArgumentException When the length is missing.
     */
    protected function requireLength(?int $length): int
    {
        if ($length === null) {
            throw new \InvalidArgumentException('A string column requires a length.');
        }
        return $length;
    }
}