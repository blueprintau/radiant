<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\ValueCodecs;

use Override;

/**
 * The default value codec — passes scalar values through untouched and
 * formats `DateTimeInterface` values to a dialect datetime string.
 *
 * Subclasses override {@see encode()} and {@see decode()} for
 * dialect-specific adaptation (e.g. Postgres' microsecond datetimes).
 */
class DefaultValueCodec implements ValueCodecInterface
{
    /**
     * @param string $datetimeFormat The format used to encode
     *        `DateTimeInterface` values (default `Y-m-d H:i:s`).
     * @param string $timezone The timezone datetimes are normalized to
     *        before formatting.
     */
    public function __construct(
        private string $datetimeFormat = 'Y-m-d H:i:s',
        private string $timezone = 'UTC',
    ) {}

    /**
     * PHP value → driver value (write/bind path).
     *
     * @param string|int|float|bool|null|\DateTimeInterface $value The value to
     *        encode for the driver.
     * @return string|int|float|bool|null The driver-ready value.
     */
    #[Override]
    public function encode(string|int|float|bool|null|\DateTimeInterface $value): string|int|float|bool|null
    {
        // Raw-path bindings may carry a DateTimeInterface (e.g. a Carbon in a
        // where clause); normalize to the codec's timezone and format it
        // explicitly rather than relying on PDO's implicit stringification.
        // createFromInterface() yields a fresh instance, so the caller's
        // object is never mutated.
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone($this->timezone))
                ->format($this->datetimeFormat);
        }

        return $value;
    }

    /**
     * Driver value → PHP value (read path).
     *
     * @param string|int|float|bool|null $value The driver value to decode.
     * @return string|int|float|bool|null The PHP value.
     */
    #[Override]
    public function decode(string|int|float|bool|null $value): string|int|float|bool|null
    {
        return $value;
    }
}
