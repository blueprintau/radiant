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
 * PRIMARY KEY` column). SQLite cannot drop columns before 3.35, so
 * {@see compileDropColumn()} inherits the base
 * {@see UnsupportedFeatureException} — a drop request fails fast rather than
 * silently doing nothing.
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
     * @param  ColumnType  $type
     * @param  int|null  $length
     * @return string
     */
    public function type(ColumnType $type, ?int $length = null): string
    {
        return match ($type) {
            ColumnType::String => 'varchar(' . $this->requireLength($length) . ')',
            ColumnType::BigInt => 'integer',
            ColumnType::Int => 'int',
            ColumnType::Float => 'double',
            ColumnType::Boolean => 'tinyint(1)',
            ColumnType::DateTime => 'datetime',
            ColumnType::Timestamp => 'timestamp',
            ColumnType::Json => 'text',
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

        $statements = array_values(array_filter([
            $foreignKeyConstraintsEnabled ? 'PRAGMA foreign_keys = OFF' : null,
            'CREATE TABLE ' . $this->wrap($tempName) . ' (' . $this->compileTableBody($tempBlueprint) . ')',
            $this->compileCopyTable($table, $tempName, $copyColumns),
            $this->compileDrop($table),
            $this->compileRenameTable($tempName, $table),
            $foreignKeyConstraintsEnabled ? 'PRAGMA foreign_keys = ON' : null,
        ]));

        return $statements;
    }
}