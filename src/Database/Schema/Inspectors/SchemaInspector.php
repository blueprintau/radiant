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
     * @return bool
     */
    abstract public function columnTypeMatches(string $liveType, \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $declaredType, int|null $declaredLength): bool;
}
