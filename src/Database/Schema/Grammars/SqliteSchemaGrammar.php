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
     * @param string $value The identifier to quote.
     * @return string The quoted identifier.
     */
    protected function wrap(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Map a logical column type to SQLite's native type.
     *
     * SQLite is dynamically typed, so the logical types map onto the
     * type-affinity classes. An auto-increment `bigint` PK maps to
     * `integer` — SQLite's `AUTOINCREMENT` is only valid on an
     * `INTEGER PRIMARY KEY` column.
     *
     * @param ColumnType $type The logical column type.
     * @param int|null $length The column length, if any.
     * @return string The SQLite type.
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
     * @return string The clause.
     */
    protected function autoIncrement(): string
    {
        return 'AUTOINCREMENT';
    }

    /**
     * Whether the auto-increment clause renders before `PRIMARY KEY`.
     *
     * SQLite requires `INTEGER PRIMARY KEY AUTOINCREMENT` — the clause
     * comes after PRIMARY KEY.
     *
     * @return bool False — AUTOINCREMENT follows PRIMARY KEY.
     */
    protected function autoIncrementBeforePrimaryKey(): bool
    {
        return false;
    }

    /**
     * SQLite renders partial (filtered) indexes — `CREATE INDEX ... WHERE
     * predicate`. It has no NULLS NOT DISTINCT and no DEFERRABLE — the
     * base hooks throw for those.
     *
     * @param string $predicate The declared predicate, spliced verbatim.
     * @return string The clause text.
     */
    protected function compilePartialIndexClause(string $predicate): string
    {
        return 'WHERE ' . $predicate;
    }

    /**
     * SQLite has no practical identifier length cap (the limit is a byte
     * count in the millions) — anything the user writes is valid.
     *
     * @param string $name The final identifier (index name).
     * @return void
     */
    public function assertValidIdentifier(string $name): void
    {
    }

    /**
     * SQLite drops an index by name alone: `DROP INDEX name`.
     *
     * @param string $name The index name.
     * @param string $table The table the index is on (unused — SQLite
     *        indexes live in their own namespace).
     * @return string The compiled SQL.
     */
    public function compileDropIndex(string $name, string $table): string
    {
        unset($table);

        return 'DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile the FULL table-rebuild sequence — SQLite's answer to every
     * change it cannot make in place (content drift, FK/CHECK changes).
     *
     * SQLite has no `ALTER COLUMN` syntax at all; the official answer
     * (sqlite.org's 12-step procedure) is a table rebuild, and the ORDER
     * is critical: create-new → copy → drop-old → rename — NOT
     * drop-then-create (a bare drop destroys the data; the copy step is
     * the whole point). The sequence is data-preserving.
     *
     * This is a PURE compile: every runtime input is a parameter — the
     * grammar never touches the connection (the caller reads the live
     * pragma state and passes it in; Laravel's grammar reads connection
     * state directly, Radiant's does not). The connection owns the
     * EXECUTION strategy: transaction straddle, the `foreign_key_check`
     * verification gate, and the PRAGMA restore.
     *
     * The PRAGMAs are CONDITIONAL: when `$foreignKeyConstraintsEnabled`
     * is false they are omitted entirely (array_filter drops them) — a
     * rebuild under no enforcement needs no toggle. When the table
     * participates in no FK relationship at all, the caller passes false
     * regardless of the connection's global setting, so standalone
     * rebuilds never touch global state.
     *
     * The temp table renders the SAME desired shape against the temp
     * name via {@see Blueprint::forTable()} + {@see compileTableBody()}
     * — no column-by-column re-declaration. The copy projects the
     * INTERSECTION of the live and desired column names: a column that
     * exists live but not in the desired shape (a drop) has nowhere to
     * go in the temp table, and a desired column that does not exist
     * live (an add) has nothing to copy — the intersection is exactly
     * the data that survives.
     *
     * @param Blueprint $desired The desired-state blueprint (bound to the
     *        FINAL table name — the name the table will have after the
     *        rebuild).
     * @param string $tempName The temp table name (caller-validated via
     *        {@see assertValidIdentifier()} and checked absent live).
     * @param list<string> $liveColumns The live column names.
     * @param bool $foreignKeyConstraintsEnabled Whether the connection
     *        currently has `PRAGMA foreign_keys` ON (the caller reads it;
     *        false also means "no FK involvement — skip the toggle").
     * @return list<string> The statements, in execution order.
     * @throws \InvalidArgumentException When the intersection is empty
     *         (nothing to copy — the rebuild would destroy the table).
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