<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\CastSafety;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Inspectors\PostgresSchemaInspector;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector;
use PHPUnit\Framework\TestCase;

/**
 * The modify-cast classification: a lenient default (SQLite/MySQL coerce
 * almost anything) and a strict Postgres override (Uncastable pairs fail
 * fast, data-dependent casts are Risky).
 */
final class CastSafetyTest extends TestCase
{
    /**
     * The lenient default never reports Uncastable — a same-family change
     * is Safe, anything else is Risky.
     */
    public function testLenientDefaultIsNeverUncastable(): void
    {
        $inspector = new SqliteSchemaInspector(new \PDO('sqlite::memory:'));

        self::assertSame(CastSafety::Safe, $inspector->castSafety('integer', ColumnType::Int));
        self::assertSame(CastSafety::Safe, $inspector->castSafety('text', ColumnType::String));
        self::assertSame(CastSafety::Risky, $inspector->castSafety('text', ColumnType::Int));
        self::assertSame(CastSafety::Risky, $inspector->castSafety('json', ColumnType::Int));
    }

    /**
     * Postgres fails fast on a cast no USING clause can perform.
     */
    public function testPostgresUncastablePairs(): void
    {
        $inspector = new PostgresSchemaInspector(new \PDO('sqlite::memory:'));

        self::assertSame(CastSafety::Uncastable, $inspector->castSafety('jsonb', ColumnType::Int));
        self::assertSame(CastSafety::Uncastable, $inspector->castSafety('bytea', ColumnType::Decimal));
        self::assertSame(CastSafety::Uncastable, $inspector->castSafety('timestamp', ColumnType::Int));
        self::assertSame(CastSafety::Uncastable, $inspector->castSafety('boolean', ColumnType::Float));
    }

    /**
     * Postgres flags a data-dependent text cast as Risky, and a lossless
     * numeric widening as Safe.
     */
    public function testPostgresRiskyAndSafePairs(): void
    {
        $inspector = new PostgresSchemaInspector(new \PDO('sqlite::memory:'));

        self::assertSame(CastSafety::Risky, $inspector->castSafety('text', ColumnType::Decimal));
        self::assertSame(CastSafety::Risky, $inspector->castSafety('varchar(64)', ColumnType::DateTime));
        self::assertSame(CastSafety::Safe, $inspector->castSafety('int4', ColumnType::BigInt));
        self::assertSame(CastSafety::Safe, $inspector->castSafety('text', ColumnType::String));
    }
}
