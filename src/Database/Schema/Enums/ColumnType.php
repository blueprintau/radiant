<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Enums;

/**
 * The logical column types understood by the schema layer.
 */
enum ColumnType: string
{
    /** A variable-length string (requires a length). */
    case String = 'string';

    /** A 64-bit integer. */
    case BigInt = 'bigint';

    /** A 32-bit integer. */
    case Int = 'int';

    /** A floating-point number. */
    case Float = 'float';

    /** A boolean. */
    case Boolean = 'boolean';

    /** A date-time value. */
    case DateTime = 'datetime';

    /** A Unix timestamp. */
    case Timestamp = 'timestamp';

    /** A JSON document. */
    case Json = 'json';
}