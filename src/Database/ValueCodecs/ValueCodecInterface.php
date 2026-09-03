<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\ValueCodecs;

/**
 * Adapts values between PHP and the database driver.
 *
 * `encode()` converts PHP values for the write/bind path, and `decode()`
 * converts driver values for the read path. Dialect-specific codecs (e.g.
 * Postgres' microsecond datetime format) implement this to keep the SQL
 * layer driver-agnostic.
 */
interface ValueCodecInterface
{
    /**
     * PHP value → driver value (write/bind path; the value is the field cast's output).
     *
     * Admits exactly the bindable union — scalars plus `\DateTimeInterface`,
     * which the codec formats to a dialect datetime string at bind time.
     * `ToSqlValue` objects never reach the codec (the QueryBuilder/Grammar
     * extracts and inlines them before bindings are bound).
     *
     * @param string|int|float|bool|null|\DateTimeInterface $value The value to
     *        encode for the driver.
     * @return string|int|float|bool|null The driver-ready value.
     */
    public function encode(string|int|float|bool|null|\DateTimeInterface $value): string|int|float|bool|null;

    /**
     * Driver value → PHP value (read path; the value is the field cast's input).
     *
     * Scalar↔scalar: PDO never returns objects, so decode stays scalar-only
     * (e.g. Postgres' microsecond datetime strings).
     *
     * @param string|int|float|bool|null $value The driver value to decode.
     * @return string|int|float|bool|null The PHP value.
     */
    public function decode(string|int|float|bool|null $value): string|int|float|bool|null;
}
