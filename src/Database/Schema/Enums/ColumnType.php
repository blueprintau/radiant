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

    /** A fixed-length string (requires a length) — ISO codes, padded identifiers. */
    case Char = 'char';

    /** An unbounded text column. */
    case Text = 'text';

    /** A 64-bit integer. */
    case BigInt = 'bigint';

    /** A 32-bit integer. */
    case Int = 'int';

    /** An exact fixed-point number — `precision` total digits, `scale` fractional digits. */
    case Decimal = 'decimal';

    /** A floating-point number. */
    case Float = 'float';

    /** A boolean. */
    case Boolean = 'boolean';

    /** A calendar date (no time component). */
    case Date = 'date';

    /** A date-time value. */
    case DateTime = 'datetime';

    /** A Unix timestamp. */
    case Timestamp = 'timestamp';

    /** A JSON document. */
    case Json = 'json';

    /** A constrained string — rendered as a sized string plus an inline CHECK over `values`. */
    case Enum = 'enum';

    /** Raw binary bytes. */
    case Binary = 'binary';

    /** A RFC 4122 UUID (fixed 36 characters). */
    case Uuid = 'uuid';
}