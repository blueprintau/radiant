<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * Reads the live schema — the read-side twin of the write-side
 * {@see \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar}
 * (read side of the schema layer).
 *
 * One abstract base + one subclass per dialect, mirroring the grammar
 * family. The connection owns its inspector (the same factory-hook pattern
 * as the grammar and codec), so the inspector reads from the connection it
 * belongs to and shares its transaction scope — the differ just takes
 * `$db->schemaInspector`.
 *
 * @template TSchemaGrammar of \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar = \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
 */
abstract class SchemaInspector
{
    /**
     * The dialect's schema grammar — CACHED (one instantiation per
     * inspector, not per comparison): the content-drift comparison
     * renders the declared type through the same mapping the grammar
     * uses for DDL, and a rebuild-per-check would churn objects for
     * nothing. The grammar is stateless, so sharing it is safe.
     *
     * The same pattern as the connection's `$schemaGrammar`: public
     * readonly, initialized once in the constructor from
     * {@see getDefaultSchemaGrammar()} — no private field + accessor
     * pair to keep in sync.
     *
     * @var TSchemaGrammar
     */
    public readonly \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar $schemaGrammar;

    /**
     * Create an inspector over the owning connection's PDO.
     *
     * @param \PDO $pdo The connection's PDO — never its own.
     */
    public function __construct(
        protected readonly \PDO $pdo,
    ) {
        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }

    /**
     * The dialect's schema grammar — the factory hook (the same pattern
     * as the connection's getDefaultSchemaGrammar()).
     *
     * @return TSchemaGrammar The grammar.
     */
    abstract protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;

    /**
     * Every table name in the live schema.
     *
     * @return list<string> The table names.
     */
    abstract public function tables(): array;

    /**
     * One table's live schema.
     *
     * @param string $name The table name.
     * @return LiveTable The live snapshot.
     * @throws \RuntimeException When the table does not exist.
     */
    abstract public function table(string $name): LiveTable;

    /**
     * Whether a table exists in the live schema.
     *
     * The differ's create-vs-alter branch point.
     *
     * @param string $name The table name.
     * @return bool True when the table exists.
     */
    final public function hasTable(string $name): bool
    {
        return in_array($name, $this->tables(), true);
    }

    /**
     * The live tables that declare a foreign key INTO the given table —
     * its referencing children.
     *
     * The SQLite rebuild's PRAGMA-involvement check needs this (a parent
     * table is FK-involved even though it declares no FK of its own);
     * one query per dialect replaces the N+1 loop over every table's
     * full snapshot.
     *
     * @param string $table The referenced table.
     * @return list<string> The referencing table names.
     */
    abstract public function referencingTables(string $table): array;

    /**
     * Whether a live column's native type text matches the declared
     * logical type — the content-drift comparison.
     *
     * The live `type` is the dialect's NATIVE text (e.g. `varchar(100)`,
     * `integer`, `jsonb`); the declared side is a logical
     * {@see \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType} +
     * length. Only the dialect knows how its own native text maps back —
     * so the comparison lives HERE, abstract, one implementation per
     * dialect. The declared type is rendered to native text through the
     * same mapping the grammar uses, so the two sides always speak the
     * same vocabulary (the round-trip guarantee: what the grammar renders
     * is what the inspector reads back).
     *
     * @param string $liveType The live column's native type text.
     * @param \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $declaredType The declared logical type.
     * @param int|null $declaredLength The declared length (strings).
     * @return bool True when the live type matches the declaration.
     */
    abstract public function columnTypeMatches(string $liveType, \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $declaredType, int|null $declaredLength): bool;
}
