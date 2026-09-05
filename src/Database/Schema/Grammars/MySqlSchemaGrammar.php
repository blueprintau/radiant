<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\ColumnType;
use BlueprintAU\Radiant\Database\Schema\SchemaOperation;

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
     * Compile an `ALTER TABLE ... DROP COLUMN` statement.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The columns to drop.
     * @return string The compiled SQL.
     */
    protected function compileDropColumn(string $table, Blueprint $blueprint): string
    {
        $columns = $blueprint->getDropColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot drop columns with no columns defined.');
        }

        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP COLUMN ' . implode(', DROP COLUMN ', array_map(
            fn (string $column) => $this->wrap($column),
            $columns,
        ));
    }
}