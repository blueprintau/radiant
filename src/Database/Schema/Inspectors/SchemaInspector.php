<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * Reads the live schema — the read-side twin of the write-side
 * {@see \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar}.
 *
 * @template TSchemaGrammar of \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar = \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
 */
abstract class SchemaInspector
{
    /**
     * The dialect's schema grammar — cached (one instantiation per
     * inspector, not per comparison).
     *
     * @var TSchemaGrammar
     */
    public readonly \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar $schemaGrammar;

    /**
     * Create an inspector over the owning connection's PDO.
     *
     * @param  \PDO  $pdo
     */
    public function __construct(
        protected readonly \PDO $pdo,
    ) {
        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }

    /**
     * The dialect's schema grammar — the factory hook.
     *
     * @return TSchemaGrammar
     */
    abstract protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;

    /**
     * Every table name in the live schema.
     *
     * @return list<string>
     */
    abstract public function tables(): array;

    /**
     * One table's live schema.
     *
     * @param  string  $name
     * @return LiveTable
     * @throws \RuntimeException
     */
    abstract public function table(string $name): LiveTable;

    /**
     * Whether a table exists in the live schema.
     *
     * @param  string  $name
     * @return bool
     */
    final public function hasTable(string $name): bool
    {
        return in_array($name, $this->tables(), true);
    }

    /**
     * The live tables that declare a foreign key into the given table —
     * its referencing children.
     *
     * @param  string  $table
     * @return list<string>
     */
    abstract public function referencingTables(string $table): array;

    /**
     * Whether a live column's native type text matches the declared
     * logical type — the content-drift comparison.
     *
     * @param  string  $liveType
     * @param  \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType  $declaredType
     * @param  int|null  $declaredLength
     * @param  int|null  $declaredPrecision
     * @param  int|null  $declaredScale  Fractional digits for a decimal column.
     * @return bool
     */
    abstract public function columnTypeMatches(string $liveType, \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $declaredType, int|null $declaredLength, int|null $declaredPrecision = null, int|null $declaredScale = null): bool;

    /**
     * How safely a live column's values convert to the desired type —
     * the modify-cast classification. The default is lenient (MySQL and
     * SQLite coerce almost anything): a same-family change is Safe,
     * anything else is Risky, and nothing is Uncastable. A stricter
     * dialect (Postgres) overrides this.
     *
     * @param  string  $liveType
     * @param  \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType  $desiredType
     * @return \BlueprintAU\Radiant\Database\Schema\Enums\CastSafety
     */
    public function castSafety(string $liveType, \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $desiredType): \BlueprintAU\Radiant\Database\Schema\Enums\CastSafety
    {
        return $this->liveTypeFamily($liveType) === $this->desiredTypeFamily($desiredType)
            ? \BlueprintAU\Radiant\Database\Schema\Enums\CastSafety::Safe
            : \BlueprintAU\Radiant\Database\Schema\Enums\CastSafety::Risky;
    }

    /**
     * The coarse family a live native type string belongs to.
     *
     * @param  string  $liveType
     * @return string
     */
    protected function liveTypeFamily(string $liveType): string
    {
        $base = strtolower(preg_replace('/\(.*$/', '', trim($liveType)) ?? $liveType);

        return match (true) {
            str_contains($base, 'int') => 'number',
            in_array($base, ['numeric', 'decimal', 'float', 'double', 'double precision', 'real'], true) => 'number',
            in_array($base, ['bool', 'boolean'], true) => 'bool',
            in_array($base, ['date', 'time', 'timestamp', 'timestamptz', 'datetime'], true) => 'temporal',
            in_array($base, ['json', 'jsonb'], true) => 'json',
            in_array($base, ['blob', 'bytea', 'binary', 'varbinary'], true) => 'binary',
            default => 'string',
        };
    }

    /**
     * The coarse family a desired logical type belongs to.
     *
     * @param  \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType  $desiredType
     * @return string
     */
    protected function desiredTypeFamily(\BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $desiredType): string
    {
        return match ($desiredType) {
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Int,
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt,
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Decimal,
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Float => 'number',
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Boolean => 'bool',
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Date,
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::DateTime,
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Timestamp => 'temporal',
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Json => 'json',
            \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Binary => 'binary',
            default => 'string',
        };
    }
}
