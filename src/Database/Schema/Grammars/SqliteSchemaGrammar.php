<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

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
    protected function type(ColumnType $type, ?int $length = null): string
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
}