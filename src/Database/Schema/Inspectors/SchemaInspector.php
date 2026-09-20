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
 */
abstract class SchemaInspector
{
    /**
     * Create an inspector over the owning connection's PDO.
     *
     * @param \PDO $pdo The connection's PDO — never its own.
     */
    public function __construct(
        protected readonly \PDO $pdo,
    ) {
    }

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
}
