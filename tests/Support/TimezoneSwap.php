<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

/**
 * Swaps the process-global default timezone around a callable and
 * restores it on every exit path.
 *
 * `date.timezone` is process-global state: a test that swaps it and
 * throws before restoring poisons every later test in the run with
 * order-dependent failures. Wrapping the swap in `finally` here makes
 * the restore unconditional even when the subject throws — the failing
 * assertion surfaces, the zone never leaks.
 */
final class TimezoneSwap
{
    /**
     * Run a callable under a different default timezone.
     *
     * An invalid identifier throws instead of silently leaving the host
     * zone in place — a rejected swap that still ran the callback would
     * make a timezone regression pass vacuously on a UTC CI host.
     *
     * @template TReturn
     *
     * @param  string  $timezoneId
     * @param  callable(): TReturn  $callback
     * @return TReturn
     * @throws \RuntimeException
     */
    public static function under(string $timezoneId, callable $callback): mixed
    {
        // date_default_timezone_set() emits an E_WARNING before returning
        // false for an unknown identifier — pre-validate through
        // DateTimeZone, which shares the identifier parser and throws
        // instead, keeping the failure warning-free.
        try {
            new \DateTimeZone($timezoneId);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "The timezone identifier [{$timezoneId}] is invalid.",
                0,
                $e,
            );
        }

        $previous = date_default_timezone_get();

        if (!date_default_timezone_set($timezoneId)) {
            throw new \RuntimeException(
                "The timezone identifier [{$timezoneId}] is invalid.",
            );
        }

        try {
            return $callback();
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
