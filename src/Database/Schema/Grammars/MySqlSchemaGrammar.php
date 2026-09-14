<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;

/**
 * The MySQL dialect of the schema grammar.
 *
 * Identifiers are quoted with backticks (embedded backticks doubled).
 * Auto-increment renders as `AUTO_INCREMENT`; MySQL supports dropping
 * columns natively.
 */
class MySqlSchemaGrammar extends SchemaGrammar
{
    /**
     * Wrap an identifier in MySQL backticks.
     *
     * @param string $value The identifier to quote.
     * @return string The quoted identifier.
     */
    protected function wrap(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Map a logical column type to MySQL's native type.
     *
     * @param ColumnType $type The logical column type.
     * @param int|null $length The column length, if any.
     * @return string The MySQL type.
     */
    protected function type(ColumnType $type, ?int $length = null): string
    {
        return match ($type) {
            ColumnType::String => 'varchar(' . $this->requireLength($length) . ')',
            ColumnType::BigInt => 'bigint',
            ColumnType::Int => 'int',
            ColumnType::Float => 'double',
            ColumnType::Boolean => 'tinyint(1)',
            ColumnType::DateTime => 'datetime',
            ColumnType::Timestamp => 'timestamp',
            ColumnType::Json => 'json',
        };
    }

    /**
     * The MySQL auto-increment clause.
     *
     * @return string The clause.
     */
    protected function autoIncrement(): string
    {
        return 'AUTO_INCREMENT';
    }

    /**
     * MySQL has NO partial (filtered) indexes, NO NULLS NOT DISTINCT, and
     * NO DEFERRABLE foreign keys — the base clause hooks throw, and MySQL
     * overrides none of them, so declaring any of those options fails
     * fast at compile time (Postgres — and for partial indexes SQLite —
     * render them).
     */

    /**
     * MySQL drops an index relative to its table: `ALTER TABLE … DROP INDEX`.
     *
     * @param string $name The index name.
     * @param string $table The table the index is on.
     * @return string The compiled SQL.
     */
    public function compileDropIndex(string $name, string $table): string
    {
        $this->assertValidIdentifier($name);

        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile an `ALTER TABLE ... DROP COLUMN` statement.
     *
     * @param Blueprint $blueprint The table and columns to drop.
     * @return string The compiled SQL.
     */
    protected function compileDropColumn(Blueprint $blueprint): string
    {
        $table = $blueprint->getTable();
        $columns = $blueprint->getDropColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot drop columns with no columns defined.');
        }

        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP COLUMN ' . implode(', DROP COLUMN ', array_map(
            fn (string $column) => $this->wrap($column),
            $columns,
        ));
    }

    /**
     * MySQL caps identifiers at 64 characters — a derived index name over
     * a long table/column set can exceed it. Fail fast at compile time
     * (Doctrine's pattern: the dialect validates, never silently
     * truncates — a truncated name is not stable across syncs and would
     * break the differ).
     *
     * @param string $name The final identifier (index name).
     * @return void
     * @throws \InvalidArgumentException When the identifier exceeds 64 chars.
     */
    public function assertValidIdentifier(string $name): void
    {
        if (strlen($name) > 64) {
            throw new \InvalidArgumentException(sprintf(
                'Identifier [%s] exceeds MySQL\'s 64-character limit (%d chars); '
                . 'declare a shorter #[Unique(name: ...)] / #[Index(name: ...)].',
                $name,
                strlen($name),
            ));
        }
    }
}
