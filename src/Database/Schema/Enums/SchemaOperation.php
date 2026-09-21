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

    /**
     * Rename a table (differ output when the desired blueprint declares
     * `renamedFrom()`; `apply()` → `renameTable()`). Non-destructive:
     * a rename moves the table and every row with it — no data is lost,
     * and indexes/constraints travel with the table.
     */
    case RenameTable = 'rename_table';

    /**
     * Rename a column (differ output when the desired blueprint declares
     * `renameColumn()`; `apply()` → `renameColumn()`). Non-destructive:
     * the column's data travels with the rename on every dialect
     * (MySQL 8.0+, Postgres, SQLite 3.25+ all support `RENAME COLUMN`).
     */
    case RenameColumn = 'rename_column';

    /**
     * Modify one or more columns in place — a content drift (type,
     * nullability, or default changed on a column that exists on both
     * sides). `apply()` → `modifyColumn()`. Destructive when the change
     * tightens nullability or shrinks the type/length (existing rows may
     * violate the new shape); non-destructive for a default-only change.
     * Dialects without an in-place form (SQLite) route through the table
     * rebuild instead.
     */
    case ModifyColumn = 'modify';

    /**
     * Add a foreign-key constraint to an existing table (differ output;
     * `apply()` → `addForeignKey()`). Non-destructive: adding a
     * constraint cannot lose data (though existing rows may violate it —
     * the database rejects the statement loudly in that case).
     */
    case AddForeignKey = 'add_foreign_key';

    /**
     * Drop a foreign-key constraint from an existing table (differ
     * output; `apply()` → `dropForeignKey()`). Non-destructive in the
     * data sense — the constraint goes, the rows stay — but it weakens
     * integrity, so the description says so.
     */
    case DropForeignKey = 'drop_foreign_key';

    /**
     * Add a CHECK constraint to an existing table (differ output;
     * `apply()` → `addCheck()`). Non-destructive: existing rows that
     * violate the new predicate make the database reject the statement
     * loudly — never silently.
     */
    case AddCheck = 'add_check';

    /**
     * Drop a CHECK constraint from an existing table (differ output;
     * `apply()` → `dropCheck()`). Non-destructive in the data sense —
     * the constraint goes, the rows stay — but it weakens integrity,
     * so the description says so.
     */
    case DropCheck = 'drop_check';
}