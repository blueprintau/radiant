<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Grammars;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * The MySQL dialect of the schema grammar.
 *
 * Identifiers are quoted with backticks (embedded backticks doubled).
 * Auto-increment renders as `AUTO_INCREMENT`; MySQL supports dropping
 * columns natively.
 */
final class MySqlSchemaGrammar extends SchemaGrammar
{
    /**
     * Wrap an identifier in MySQL backticks.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrap(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Map a logical column type to MySQL's native type.
     *
     * Datetime types render fractional seconds when a precision is declared
     * (`datetime(3)`); MySQL 5.6.4+ stores the declared digits natively.
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
            ColumnType::BigInt => 'bigint',
            ColumnType::Int => 'int',
            ColumnType::Decimal => 'decimal' . $this->suffix($precision, $scale),
            ColumnType::Float => 'double',
            ColumnType::Boolean => 'tinyint(1)',
            ColumnType::Date => 'date',
            ColumnType::DateTime => 'datetime' . $this->suffix($precision),
            ColumnType::Timestamp => 'timestamp' . $this->suffix($precision),
            ColumnType::Json => 'json',
            ColumnType::Enum => 'varchar' . $this->suffix($this->requireLength($length)),
            ColumnType::Binary => $length === null ? 'blob' : 'varbinary' . $this->suffix($length),
            ColumnType::Uuid => 'char(36)',
        };
    }

    /**
     * The MySQL auto-increment clause.
     *
     * @return string
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
     * @param  string  $name
     * @param  string  $table
     * @return string
     */
    public function compileDropIndex(string $name, string $table): string
    {
        $this->assertValidIdentifier($name);

        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile an `ALTER TABLE ... DROP COLUMN` statement.
     *
     * @param  Blueprint  $blueprint
     * @return string
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
     * Compile an `ALTER TABLE ... MODIFY COLUMN` statement — MySQL's
     * in-place content-drift form.
     *
     * @param  Blueprint  $blueprint
     * @return list<string>
     */
    public function compileModifyColumn(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();
        $columns = $blueprint->getColumns();
        if ($columns === []) {
            throw new \InvalidArgumentException('Cannot modify columns with no columns defined.');
        }

        return array_map(
            fn (array $column): string => 'ALTER TABLE ' . $this->wrap($table)
                . ' MODIFY ' . $this->compileColumnDefinition($column),
            $columns,
        );
    }

    /**
     * Compile an `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY`
     * statement — MySQL's in-place FK-add form.
     *
     * @param  string  $table
     * @param  array{columns: list<string>, references: list<string>, onDelete: \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction|null, onUpdate: \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}  $foreignKey
     * @param  string  $name
     * @return string
     */
    public function compileAddForeignKey(string $table, array $foreignKey, string $name): string
    {
        $this->assertValidIdentifier($name);

        return 'ALTER TABLE ' . $this->wrap($table) . ' ADD CONSTRAINT ' . $this->wrap($name) . ' '
            . $this->compileForeignKeyConstraint($foreignKey);
    }

    /**
     * Compile an `ALTER TABLE ... DROP FOREIGN KEY` statement — MySQL's
     * in-place FK-drop form.
     *
     * @param  string  $table
     * @param  string  $name
     * @return string
     */
    public function compileDropForeignKey(string $table, string $name): string
    {
        $this->assertValidIdentifier($name);

        return 'ALTER TABLE ' . $this->wrap($table) . ' DROP FOREIGN KEY ' . $this->wrap($name);
    }

    /**
     * Compile an `ALTER TABLE ... ADD CONSTRAINT ... CHECK` statement —
     * MySQL's in-place CHECK-add form.
     *
     * @param  string  $table
     * @param  string  $name
     * @param  string  $expression
     * @return string
     */
    public function compileAddCheck(string $table, string $name, string $expression): string
    {
        $this->assertValidIdentifier($name);

        return 'ALTER TABLE ' . $this->wrap($table) . ' ADD CONSTRAINT ' . $this->wrap($name)
            . ' CHECK (' . $expression . ')';
    }

    /**
     * MySQL caps identifiers at 64 characters — fail fast at compile
     * time, never silently truncate.
     *
     * @param  string  $name
     * @return void
     * @throws \InvalidArgumentException
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
