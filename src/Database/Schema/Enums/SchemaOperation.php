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

    /** Create a whole table. */
    case CreateTable = 'create';

    /** Drop a whole table (destructive). */
    case DropTable = 'drop_table';

    /** Rebuild the table's indexes. */
    case AlterIndexes = 'alter_indexes';

    /** Rename a table. */
    case RenameTable = 'rename_table';

    /** Rename a column. */
    case RenameColumn = 'rename_column';

    /** Modify one or more columns in place. */
    case ModifyColumn = 'modify';

    /** Add a foreign-key constraint to an existing table. */
    case AddForeignKey = 'add_foreign_key';

    /** Drop a foreign-key constraint from an existing table. */
    case DropForeignKey = 'drop_foreign_key';

    /** Add a CHECK constraint to an existing table. */
    case AddCheck = 'add_check';

    /** Drop a CHECK constraint from an existing table. */
    case DropCheck = 'drop_check';
}