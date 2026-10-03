<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * The SQLite dialect of the schema grammar.
 *
 * Identifiers are quoted with double quotes (embedded quotes doubled).
 * Auto-increment renders as `AUTOINCREMENT` (only valid on an `INTEGER
 * PRIMARY KEY` column). SQLite 3.35+ drops columns natively, and `ALTER
 * TABLE` accepts a single `ADD COLUMN` clause per statement — a
 * multi-column add compiles one statement per column.
 */
final class SqliteSchemaGrammar extends SchemaGrammar
{
    /**
     * Wrap an identifier in SQLite double quotes.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrap(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Map a logical column type to SQLite's native type.
     *
     * Datetime types render declared precision (`datetime(3)`) for
     * cross-dialect DDL parity; SQLite's type affinity ignores it, and the
     * driver stores whatever textual precision the bound value carries.
     *
     * @param  ColumnType  $type
     * @param  int|null  $length
     * @param  int|null  $precision
     * @param  int|null  $scale
     * @return string
     */
    public function type(ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        return match ($type) {
            ColumnType::String => 'varchar' . $this->suffix($this->requireLength($length)),
            ColumnType::Char => 'char' . $this->suffix($this->requireLength($length)),
            ColumnType::Text => 'text',
            ColumnType::BigInt => 'integer',
            ColumnType::Int => 'int',
            ColumnType::Decimal => 'numeric' . $this->suffix($precision, $scale),
            ColumnType::Float => 'double',
            ColumnType::Boolean => 'tinyint(1)',
            ColumnType::Date => 'text',
            ColumnType::DateTime => 'datetime' . $this->suffix($precision),
            ColumnType::Timestamp => 'timestamp' . $this->suffix($precision),
            ColumnType::Json => 'text',
            ColumnType::Enum => 'varchar' . $this->suffix($this->requireLength($length)),
            ColumnType::Binary => 'blob',
            ColumnType::Uuid => 'text',
        };
    }

    /**
     * The SQLite auto-increment clause.
     *
     * @return string
     */
    protected function autoIncrement(): string
    {
        return 'AUTOINCREMENT';
    }

    /**
     * Whether the auto-increment clause renders before `PRIMARY KEY`.
     *
     * @return bool
     */
    protected function autoIncrementBeforePrimaryKey(): bool
    {
        return false;
    }

    /**
     * SQLite renders partial (filtered) indexes — `CREATE INDEX ... WHERE
     * predicate`.
     *
     * @param  string  $predicate
     * @return string
     */
    protected function compilePartialIndexClause(string $predicate): string
    {
        return 'WHERE ' . $predicate;
    }

    /**
     * SQLite has no practical identifier length cap.
     *
     * @param  string  $name
     * @return void
     */
    public function assertValidIdentifier(string $name): void
    {
    }

    /**
     * SQLite drops an index by name alone: `DROP INDEX name`.
     *
     * @param  string  $name
     * @param  string  $table
     * @return string
     */
    public function compileDropIndex(string $name, string $table): string
    {
        unset($table);

        return 'DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile the `ALTER TABLE ... ADD COLUMN` statements.
     *
     * SQLite accepts a single `ADD COLUMN` clause per statement, so a
     * multi-column add is one statement per column.
     *
     * @param  Blueprint  $blueprint
     * @return list<string>
     */
    #[\Override]
    public function compileAddColumns(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();
        $columns = $blueprint->getColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot add columns with no columns defined.');
        }

        $backfills = $blueprint->getBackfills();

        // A NOT NULL column without a default cannot be added to a
        // non-empty table — its backfill renders as a temporary inline
        // DEFAULT so the ADD succeeds and fills the existing rows (the
        // rebuild, not this path, produces the no-final-default shape).
        $statements = array_map(
            fn (array $column): string => 'ALTER TABLE ' . $this->wrap($table)
                . ' ADD COLUMN ' . $this->compileAddColumn($this->withTemporaryDefault($column, $backfills)),
            $columns,
        );

        // SQLite cannot SET/DROP a column default in place, so a backfill
        // on an in-place (nullable) add is an UPDATE of the existing rows.
        // A NOT NULL-no-default column already got its backfill from the
        // inline DEFAULT above — no UPDATE for it.
        foreach ($backfills as $column => $value) {
            if ($this->addedNotNullWithoutDefault($blueprint, $column)) {
                continue;
            }

            $statements[] = 'UPDATE ' . $this->wrap($table) . ' SET ' . $this->wrap($column)
                . ' = ' . $this->compileDefault($value) . ' WHERE ' . $this->wrap($column) . ' IS NULL';
        }

        return $statements;
    }

    /**
     * Whether the named column is an added NOT NULL column without a
     * declared default.
     *
     * @param  Blueprint  $blueprint
     * @param  string  $name
     * @return bool
     */
    private function addedNotNullWithoutDefault(Blueprint $blueprint, string $name): bool
    {
        foreach ($blueprint->getColumns() as $column) {
            if ($column['name'] === $name) {
                return $column['nullable'] !== true && $column['default'] === null;
            }
        }

        return false;
    }

    /**
     * Compile the `ALTER TABLE ... DROP COLUMN` statements.
     *
     * SQLite 3.35+ drops columns natively, one `DROP COLUMN` clause per
     * statement.
     *
     * @param  Blueprint  $blueprint
     * @return list<string>
     */
    #[\Override]
    public function compileDropColumns(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();
        $columns = $blueprint->getDropColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot drop columns with no columns defined.');
        }

        return array_map(
            fn (string $column): string => 'ALTER TABLE ' . $this->wrap($table)
                . ' DROP COLUMN ' . $this->compileDropColumn($column),
            $columns,
        );
    }

    /**
     * Compile one column's `DROP COLUMN` clause.
     *
     * @param  string  $column
     * @return string
     */
    #[\Override]
    protected function compileDropColumn(string $column): string
    {
        return $this->wrap($column);
    }

    /**
     * Compile the full table-rebuild sequence — SQLite's answer to every
     * change it cannot make in place (content drift, FK/CHECK changes).
     *
     * The order is critical: create-new → copy → drop-old → rename. The
     * copy projects the intersection of the live and desired column names.
     *
     * @param  Blueprint  $desired
     * @param  string  $tempName
     * @param  list<string>  $liveColumns
     * @param  bool  $foreignKeyConstraintsEnabled  Whether the connection currently has `PRAGMA foreign_keys` ON.
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    public function compileRebuildTable(
        Blueprint $desired,
        string $tempName,
        array $liveColumns,
        bool $foreignKeyConstraintsEnabled,
    ): array {
        $table = $desired->getTable();

        // The copy projection: live ∩ desired — the data that survives.
        $desiredNames = array_map(fn (array $column) => $column['name'], $desired->getColumns());
        $copyColumns = array_values(array_intersect($liveColumns, $desiredNames));

        if ($copyColumns === []) {
            throw new \InvalidArgumentException(sprintf(
                'A table rebuild of [%s] would copy no columns (live [%s] vs desired [%s]); '
                . 'the rebuild is refused — drop and re-create the table explicitly instead.',
                $table,
                implode(', ', $liveColumns),
                implode(', ', $desiredNames),
            ));
        }

        // The temp blueprint: same desired shape, bound to the temp name,
        // WITHOUT indexes (they are re-created from the original blueprint
        // after the rename — derived names must carry the final table
        // name, never the temp name).
        $tempBlueprint = $desired->forTable($tempName);

        // An added NOT NULL column has no source in the copy projection —
        // the copy supplies its value for every surviving row: the
        // blueprint's explicit backfill(), else the column's declared
        // default — never a guessed zero value. A NOT NULL added column
        // with neither fails fast at compile time. A column WITH a value
        // keeps its NOT NULL in the temp definition (its copy projection
        // can never produce NULL), so the rebuild lands the final shape in
        // one apply; only a nullable added column copies as its natural
        // NULL and needs no relaxation at all.
        $declaredBackfills = $desired->getBackfills();
        $backfills = [];

        foreach ($desired->getColumns() as $column) {
            if (in_array($column['name'], $liveColumns, true)) {
                continue; // Not an added column — it has a copy source.
            }

            if ($column['nullable'] === true) {
                continue; // Nullable added columns copy as their natural NULL.
            }

            $value = $declaredBackfills[$column['name']] ?? $column['default'] ?? null;

            if ($value === null) {
                throw new \InvalidArgumentException(sprintf(
                    'A table rebuild of [%s] cannot add the NOT NULL column [%s]: it declares no '
                    . 'default and no backfill() value to fill the existing rows with. Declare a '
                    . 'default on the column, provide one with Blueprint::backfill(), or make the '
                    . 'column nullable.',
                    $table,
                    $column['name'],
                ));
            }

            $backfills[$column['name']] = $value;
        }

        $statements = array_values(array_filter([
            $foreignKeyConstraintsEnabled ? 'PRAGMA foreign_keys = OFF' : null,
            'CREATE TABLE ' . $this->wrap($tempName) . ' (' . $this->compileTableBody($tempBlueprint) . ')',
            $this->compileCopyTableWithBackfill($table, $tempName, $copyColumns, $backfills),
            $this->compileDrop($table),
            $this->compileRenameTable($tempName, $table),
            $foreignKeyConstraintsEnabled ? 'PRAGMA foreign_keys = ON' : null,
        ]));

        return $statements;
    }

    /**
     * Compile the data-copy statement, backfilling the added NOT NULL
     * columns with their values.
     *
     * @param  string  $from
     * @param  string  $to
     * @param  list<string>  $columns  The live ∩ desired projection.
     * @param  array<string, mixed>  $backfills  Added column => backfill value.
     * @return string
     */
    private function compileCopyTableWithBackfill(string $from, string $to, array $columns, array $backfills): string
    {
        if ($backfills === []) {
            return $this->compileCopyTable($from, $to, $columns);
        }

        $targetColumns = [...$columns, ...array_keys($backfills)];

        $selectExpressions = array_map(
            fn (string $column): string => $this->wrap($column),
            $columns,
        );

        foreach ($backfills as $default) {
            $selectExpressions[] = $this->compileDefault($default);
        }

        return 'INSERT INTO ' . $this->wrap($to)
            . ' (' . implode(', ', array_map(fn (string $column) => $this->wrap($column), $targetColumns)) . ')'
            . ' SELECT ' . implode(', ', $selectExpressions)
            . ' FROM ' . $this->wrap($from);
    }
}