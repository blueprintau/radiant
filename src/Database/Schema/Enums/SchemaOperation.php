<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Enums;

/**
 * The schema-change vocabulary — the single currency of the schema layer: the differ
 * emits it, {@see \BlueprintAU\Radiant\Database\Connections\SqlConnection::apply()}
 * dispatches it, generated and hand-written migrations express it.
 */
enum SchemaOperation: string
{
    /** Add one or more columns. */
    case AddColumn = 'add';

    /** Drop one or more columns. */
    case DropColumn = 'drop';

    /** Create a whole table (differ output; `apply()` → `create()`). */
    case CreateTable = 'create';

    /** Drop a whole table (differ output; `apply()` → `drop()`, destructive). */
    case DropTable = 'drop_table';

    /**
     * Rebuild the table's indexes (differ output; `apply()` re-runs every
     * `CREATE INDEX` after dropping the drifted ones). Non-destructive:
     * an index rebuild never touches rows — an option drift (partial
     * predicate, NULLS NOT DISTINCT) is a constraint semantics change,
     * but applying it cannot lose data.
     */
    case AlterIndexes = 'alter_indexes';
}