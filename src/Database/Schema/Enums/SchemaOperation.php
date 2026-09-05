<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Enums;

/**
 * The kind of `ALTER TABLE` operation to compile.
 *
 * Mirrors the plan's `SchemaOperation` reference (§4): the schema layer is
 * DB-only, consumed by
 * {@see \BlueprintAU\Radiant\Database\Connections\SqlConnection::alter()}.
 */
enum SchemaOperation: string
{
    /** Add one or more columns. */
    case AddColumn = 'add';

    /** Drop one or more columns. */
    case DropColumn = 'drop';
}