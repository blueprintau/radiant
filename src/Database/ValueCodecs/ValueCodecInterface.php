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
     * @param  string|int|float|bool|null|\DateTimeInterface  $value
     * @return string|int|float|bool|null
     */
    public function encode(string|int|float|bool|null|\DateTimeInterface $value): string|int|float|bool|null;

    /**
     * Driver value → PHP value (read path; the value is the field cast's input).
     *
     * @param  string|int|float|bool|null  $value
     * @return string|int|float|bool|null
     */
    public function decode(string|int|float|bool|null $value): string|int|float|bool|null;
}
