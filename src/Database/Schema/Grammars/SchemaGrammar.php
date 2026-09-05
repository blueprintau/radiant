<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

use BlueprintAU\Radiant\Database\Concerns\QuotesLiterals;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\ColumnType;
use BlueprintAU\Radiant\Database\Schema\SchemaOperation;

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
     * @param ColumnType $type The logical column type.
     * @param int|null $length The column length, if any.
     * @return string The dialect's native type.
     */
    abstract protected function type(ColumnType $type, ?int $length = null): string;

    /**
     * Compile a `CREATE TABLE` statement.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The columns to create.
     * @return string The compiled SQL.
     */
    public function compileCreate(string $table, Blueprint $blueprint): string
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

        return 'CREATE TABLE ' . $this->wrap($table) . ' (' . implode(', ', $definitions) . ')';
    }

    /**
     * Compile a table-level foreign-key constraint.
     *
     * @param array{columns: list<string>, references: list<string>, onDelete: string|null, onUpdate: string|null} $foreignKey
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

        $sql = 'FOREIGN KEY (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $foreignKey['columns'])) . ') '
            . 'REFERENCES ' . $this->wrap($table) . ' (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $foreignKey['references'])) . ')';

        if ($foreignKey['onDelete'] !== null) {
            $sql .= ' ON DELETE ' . strtoupper($foreignKey['onDelete']);
        }
        if ($foreignKey['onUpdate'] !== null) {
            $sql .= ' ON UPDATE ' . strtoupper($foreignKey['onUpdate']);
        }

        return $sql;
    }

    /**
     * Compile an `ALTER TABLE` statement.
     *
     * @param string $table The table name.
     * @param SchemaOperation $operation The operation to perform.
     * @param Blueprint $blueprint The columns involved.
     * @return string The compiled SQL.
     */
    public function compileAlter(string $table, SchemaOperation $operation, Blueprint $blueprint): string
    {
        return match ($operation) {
            SchemaOperation::AddColumn => $this->compileAddColumn($table, $blueprint),
            SchemaOperation::DropColumn => $this->compileDropColumn($table, $blueprint),
        };
    }

    /**
     * Compile a `DROP TABLE` statement.
     *
     * @param string $table The table name.
     * @return string The compiled SQL.
     */
    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->wrap($table);
    }

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
     * @param string $table The table name.
     * @param Blueprint $blueprint The blueprint.
     * @return list<string> One `CREATE INDEX` statement per index.
     */
    public function compileIndexes(string $table, Blueprint $blueprint): array
    {
        return array_map(
            fn (array $index) => ($index['unique'] ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
                . $this->wrap($table . '_' . $index['name'] . '_index')
                . ' ON ' . $this->wrap($table)
                . ' (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $index['columns'])) . ')',
            $blueprint->getIndexes(),
        );
    }

    /**
     * Compile an `ALTER TABLE ... ADD COLUMN` statement.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The columns to add.
     * @return string The compiled SQL.
     */
    protected function compileAddColumn(string $table, Blueprint $blueprint): string
    {
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
     * @param string $table The table name.
     * @param Blueprint $blueprint The columns to drop.
     * @return string The compiled SQL.
     * @throws UnsupportedFeatureException Always — the base dialect cannot
     *         drop columns.
     */
    protected function compileDropColumn(string $table, Blueprint $blueprint): string
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

        $segments = [$name, $type];

        if ($column['nullable'] !== true) {
            $segments[] = 'NOT NULL';
        }

        if ($column['default'] !== null) {
            $segments[] = 'DEFAULT ' . $this->compileDefault($column['default']);
        }

        if ($column['unique'] === true) {
            $segments[] = 'UNIQUE';
        }

        // Auto-increment placement is dialect-dependent: MySQL/Postgres
        // render it before PRIMARY KEY (`AUTO_INCREMENT PRIMARY KEY`,
        // `GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`), while SQLite
        // requires it after (`INTEGER PRIMARY KEY AUTOINCREMENT`).
        if ($column['autoIncrement'] === true && $this->autoIncrementBeforePrimaryKey()) {
            $segments[] = $this->autoIncrement();
        }

        // A single-column PK is declared inline; a composite PK is declared
        // table-level (see compileCreate), so a column that is part of a
        // composite PK must not also get an inline PRIMARY KEY.
        if ($column['primaryKey'] === true && !$composite) {
            $segments[] = 'PRIMARY KEY';
        }

        if ($column['autoIncrement'] === true && !$this->autoIncrementBeforePrimaryKey()) {
            $segments[] = $this->autoIncrement();
        }

        return implode(' ', $segments);
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