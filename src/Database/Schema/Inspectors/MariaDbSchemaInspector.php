<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

use BlueprintAU\Radiant\Database\Schema\Enums\CastSafety;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use Override;

/**
 * Reads the live schema on MariaDB — `information_schema`, as MySQL
 * reports it with four divergences.
 *
 * MariaDB implements `JSON` as an alias for `LONGTEXT`, so a declared
 * Json column reads back as `longtext` — matched here to stop the differ
 * re-planning every Json column forever. MariaDB also retains integer
 * display widths that MySQL 8 dropped (`bigint(20)`), stripped here,
 * renders `current_timestamp()` (lowercase, with parens) where MySQL
 * reports `CURRENT_TIMESTAMP`, and reports the literal string `'NULL'`
 * for a nullable column with no default where MySQL reports a real
 * null — the default and type normalizations keep an
 * `Expression('CURRENT_TIMESTAMP')` column and a nullable-no-default
 * column converging on the first plan.
 *
 * @see \BlueprintAU\Radiant\Database\Schema\Inspectors\MySqlSchemaInspector
 */
final class MariaDbSchemaInspector extends MySqlSchemaInspector
{
    /**
     * The MariaDB spellings of a current-timestamp default.
     *
     * @var list<string>
     */
    private const CURRENT_TIMESTAMP_SPELLINGS = ['current_timestamp()', 'now()'];

    /**
     * Normalize a live column type's MariaDB spelling.
     *
     * MariaDB retains integer display widths that MySQL 8 dropped
     * (`bigint(20)` where MySQL reports `bigint`) — the widths have no
     * semantic effect, so they are stripped. The exception is
     * `tinyint(1)`: it IS the Boolean rendering, not a display width,
     * and the type comparison needs it verbatim.
     *
     * @param  string  $type
     * @return string
     */
    #[Override]
    protected function normalizeColumnType(string $type): string
    {
        if (strtolower($type) === 'tinyint(1)') {
            return 'tinyint(1)';
        }

        return preg_replace('/^(tinyint|smallint|mediumint|bigint|int)\(\d+\)/', '$1', $type) ?? $type;
    }

    /**
     * Whether a live column's native type text matches the declared
     * logical type — the MariaDB mapping over the MySQL one.
     *
     * @param  string  $liveType
     * @param  ColumnType  $declaredType
     * @param  int|null  $declaredLength
     * @param  int|null  $declaredPrecision
     * @param  int|null  $declaredScale
     * @return bool
     */
    #[Override]
    public function columnTypeMatches(string $liveType, ColumnType $declaredType, int|null $declaredLength, int|null $declaredPrecision = null, int|null $declaredScale = null): bool
    {
        // MariaDB stores JSON as LONGTEXT: a declared Json column reads
        // back as `longtext` (no length suffix).
        if ($declaredType === ColumnType::Json
            && strtolower($liveType) === 'longtext'
            && $declaredLength === null) {
            return true;
        }

        // MariaDB retains integer display widths MySQL 8 dropped — strip
        // them so the comparison sees the bare type text.
        $liveType = $this->normalizeColumnType(strtolower($liveType));

        return parent::columnTypeMatches($liveType, $declaredType, $declaredLength, $declaredPrecision, $declaredScale);
    }

    /**
     * The MariaDB modify-cast classification — a `longtext` column
     * converts to a Json column exactly as MariaDB does when it applies
     * its json_valid CHECK.
     *
     * @param  string  $liveType
     * @param  ColumnType  $desiredType
     * @return CastSafety
     */
    #[Override]
    public function castSafety(string $liveType, ColumnType $desiredType): CastSafety
    {
        // The family comparisons see the same stripped text the differ
        // read from the columns.
        $liveType = $this->normalizeColumnType(strtolower($liveType));

        if ($desiredType === ColumnType::Json && $liveType === 'longtext') {
            return CastSafety::Safe;
        }

        return parent::castSafety($liveType, $desiredType);
    }

    /**
     * Normalize a live column default's MariaDB spelling.
     *
     * MariaDB renders `current_timestamp()` (and `now()`) where MySQL
     * reports `CURRENT_TIMESTAMP`, and reports the literal string
     * `'NULL'` for a nullable column with no default where MySQL reports
     * a real null.
     *
     * @param  mixed  $default
     * @return mixed
     */
    #[\Override]
    protected function normalizeColumnDefault(mixed $default): mixed
    {
        // A nullable column with no default reports the literal string
        // 'NULL' — normalize to the real null MySQL reports.
        if (is_string($default) && strtoupper($default) === 'NULL') {
            return null;
        }

        if (!is_string($default)
            || !in_array(strtolower(trim($default)), self::CURRENT_TIMESTAMP_SPELLINGS, true)) {
            return $default;
        }

        return 'CURRENT_TIMESTAMP';
    }
}
