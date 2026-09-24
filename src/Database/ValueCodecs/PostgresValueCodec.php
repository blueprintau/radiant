<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\ValueCodecs;


/**
 * The Postgres value codec — formats `DateTimeInterface` values with
 * microsecond precision.
 *
 * Postgres' native `timestamp` type stores microsecond precision, so on the
 * write path datetimes must be formatted as `Y-m-d H:i:s.u`. On the read
 * path pdo_pgsql returns `timestamp` columns as strings in the same format,
 * and PostgreSQL casts numeric/bool columns to PHP native types itself, so
 * the inherited identity `decode()` is correct.
 */
final class PostgresValueCodec extends DefaultValueCodec
{
    /**
     * @param  string  $timezone
     */
    public function __construct(string $timezone = 'UTC')
    {
        parent::__construct('Y-m-d H:i:s.u', $timezone);
    }
}