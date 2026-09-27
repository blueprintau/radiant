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
 * @phpstan-import-type ColumnShape from \BlueprintAU\Radiant\Database\Schema\Blueprint
 */
abstract class SchemaGrammar
{
    use QuotesLiterals;
    use ConcatenatesStatements;

    /**
     * Wrap an identifier in the dialect's quote character.
     *
     * @param  string  $value
     * @return string
     */
    abstract protected function wrap(string $value): string;

    /**
     * Map a logical column type to the dialect's native type.
     *
     * @param  ColumnType  $type
     * @param  int|null  $length
     * @param  int|null  $precision  Fractional-seconds digits (1–6) for datetime types.
     * @param  int|null  $scale  Fractional digits for a decimal column.
     * @return string
     */
    abstract public function type(ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string;

    /**
     * Compile a `CREATE TABLE` statement.
     *
     * @param  Blueprint  $blueprint
     * @return string
     */
    final public function compileCreate(Blueprint $blueprint): string
    {
        return 'CREATE TABLE ' . $this->wrap($blueprint->getTable()) . ' (' . $this->compileTableBody($blueprint) . ')';
    }

    /**
     * Compile the parenthesized body of a `CREATE TABLE`.
     *
     * @param  Blueprint  $blueprint
     * @return string
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
     * @param  array{name: string|null, expression: string}  $check
     * @return string
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
     * @param  array{columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}  $foreignKey
     * @return string
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
     * @param  bool  $initiallyDeferred
     * @return string
     * @throws UnsupportedFeatureException
     */
    protected function compileDeferrableClause(bool $initiallyDeferred): string
    {
        throw new UnsupportedFeatureException(
            'This dialect does not support DEFERRABLE foreign keys.'
        );
    }

    /**
     * Compile an `ALTER TABLE ... ADD COLUMN` statement.
     *
     * @param  Blueprint  $blueprint
     * @return string
     * @throws \InvalidArgumentException
     */
    final public function compileAddColumns(Blueprint $blueprint): string
    {
        return $this->compileAddColumn($blueprint);
    }

    /**
     * Compile an `ALTER TABLE ... DROP COLUMN` statement.
     *
     * @param  Blueprint  $blueprint
     * @return string
     * @throws UnsupportedFeatureException
     */
    final public function compileDropColumns(Blueprint $blueprint): string
    {
        return $this->compileDropColumn($blueprint);
    }

    /**
     * Compile a `DROP TABLE` statement.
     *
     * @param  string  $table
     * @return string
     */
    final public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->wrap($table);
    }

    /**
     * Compile an `ALTER TABLE ... RENAME TO` statement.
     *
     * @param  string  $from
     * @param  string  $to
     * @return string
     */
    final public function compileRenameTable(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($from) . ' RENAME TO ' . $this->wrap($to);
    }

    /**
     * Compile an `ALTER TABLE ... RENAME COLUMN` statement.
     *
     * @param  string  $table
     * @param  string  $from
     * @param  string  $to
     * @return string
     */
    final public function compileRenameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($table) . ' RENAME COLUMN '
            . $this->wrap($from) . ' TO ' . $this->wrap($to);
    }

    /**
     * Compile an `INSERT INTO ... SELECT` data-copy statement.
     *
     * @param  string  $from
     * @param  string  $to
     * @param  list<string>  $columns
     * @return string
     * @throws \InvalidArgumentException
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
     * @param  Blueprint  $blueprint
     * @return list<string>
     * @throws UnsupportedFeatureException
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
     * @param  string  $table
     * @param  array{columns: list<string>, references: list<string>, onDelete: ForeignKeyAction|null, onUpdate: ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}  $foreignKey
     * @param  string  $name
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  string  $table
     * @param  string  $name
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  string  $table
     * @param  string  $name
     * @param  string  $expression
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  string  $table
     * @param  string  $name
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  string  $name
     * @param  string  $table
     * @return string
     */
    abstract public function compileDropIndex(string $name, string $table): string;

    /**
     * Compile the `CREATE INDEX` statements for the blueprint's indexes.
     *
     * @param  Blueprint  $blueprint
     * @return list<string>
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
     * @param  bool  $nullsNotDistinct
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  string  $predicate
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  string  $name
     * @return void
     * @throws \InvalidArgumentException
     */
    abstract public function assertValidIdentifier(string $name): void;

    /**
     * Compile an `ALTER TABLE ... ADD COLUMN` statement.
     *
     * @param  Blueprint  $blueprint
     * @return string
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
     * @param  Blueprint  $blueprint
     * @return string
     * @throws UnsupportedFeatureException
     */
    protected function compileDropColumn(Blueprint $blueprint): string
    {
        throw new UnsupportedFeatureException('This dialect does not support dropping columns.');
    }

    /**
     * Compile a single column definition.
     *
     * @param  ColumnShape  $column
     * @param  bool  $composite  Whether the column is part of a composite primary key.
     * @return string
     */
    protected function compileColumnDefinition(array $column, bool $composite = false): string
    {
        $name = $this->wrap($column['name']);
        $type = $this->type($column['type'], $column['length'], $column['precision'], $column['scale']);

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
            $this->compileEnumCheckClause($column),
        ]);
    }

    /**
     * The inline CHECK clause for an enum column — the portable enum
     * rendering (a sized string constrained to its declared values).
     *
     * @param  ColumnShape  $column
     * @return string
     */
    protected function compileEnumCheckClause(array $column): string
    {
        $values = $column['values'] ?? null;

        if ($column['type'] !== ColumnType::Enum || $values === null || $values === []) {
            return '';
        }

        $literals = implode(', ', array_map(
            fn (string $value) => $this->quoteLiteral($value),
            $values,
        ));

        return 'CHECK (' . $this->wrap($column['name']) . ' IN (' . $literals . '))';
    }

    /**
     * Compile a column default.
     *
     * @param  mixed  $default  A scalar or an Expression.
     * @return string
     * @throws \InvalidArgumentException
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
     * @return string
     */
    abstract protected function autoIncrement(): string;

    /**
     * Whether the auto-increment clause renders before `PRIMARY KEY`.
     *
     * @return bool
     */
    protected function autoIncrementBeforePrimaryKey(): bool
    {
        return true;
    }

    /**
     * Require a length for a string column.
     *
     * @param  int|null  $length
     * @return int
     * @throws \InvalidArgumentException
     */
    protected function requireLength(?int $length): int
    {
        if ($length === null) {
            throw new \InvalidArgumentException('A string column requires a length.');
        }
        return $length;
    }

    /**
     * The parenthesized suffix for a sized type — `(a,b)` when any part is
     * non-null, an empty string when all are null.
     *
     * @param  \Stringable|int|null  ...$parts  The size parts (length, precision, scale).
     * @return string
     */
    protected function suffix(\Stringable|int|null ...$parts): string
    {
        // Drop the nulls, then stringify what's left. The filter is
        // explicit (`!== null`), not the default falsy filter: a numeric
        // part of 0 stringifies to '0', which is falsy and would be
        // silently dropped.
        $rendered = array_map(
            fn (\Stringable|int $part): string => (string) $part,
            array_filter($parts, fn (\Stringable|int|null $part): bool => $part !== null),
        );

        if ($rendered === []) {
            return '';
        }

        return '(' . implode(',', $rendered) . ')';
    }
}