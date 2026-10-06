<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\TimezoneSwap;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\CodecKind;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\CodecIntStatus;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\CodecProbe;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\CodecStatus;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\UserPreferences;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\EmptyEnumForCodec;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the Column codec arms — type-compatibility guards, enum value
 * resolution, precision/default validation and the encode/decode edges.
 */
final class ColumnCodecTest extends TestCase
{
    /**
     * A probe class with typed properties for direct guard calls.
     *
     * @var class-string<Model>
     */
    private const PROBE = CodecProbe::class;

    /**
     * An untyped property fails the type-compatibility guard.
     */
    public function testUntypedPropertyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'property [untyped] is untyped; a #[Column] property must declare a single named type',
        );

        (new Column(type: ColumnType::String, length: 8))
            ->assertTypeCompatible(null, self::PROBE, 'untyped');
    }

    /**
     * A boolean column cannot store an int property — the matrix-miss
     * throw lists the compatible column types.
     */
    public function testMatrixMissListsCompatibleTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'declares a [boolean] column, which cannot store the field type [int].'
                . ' Compatible column types for [int]: int, bigint, timestamp.',
        );

        (new Column(type: ColumnType::Boolean))
            ->assertTypeCompatible('int', self::PROBE, 'count');
    }

    /**
     * A property type with no matrix entry and no enum/object match hits
     * the "none" arm of the compatible-types list.
     */
    public function testMatrixMissWithNoCompatibleTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Compatible column types for [stdClass]: none.',
        );

        (new Column(type: ColumnType::String, length: 8))
            ->assertTypeCompatible(\stdClass::class, self::PROBE, 'name');
    }

    /**
     * Datetime precision above 6 fails the range guard.
     */
    public function testDatetimePrecisionOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'declares datetime precision [7], which is out of range; use null for whole seconds'
                . ' or an integer between 1 and 6 for fractional seconds.',
        );

        (new Column(type: ColumnType::DateTime, precision: 7))
            ->assertTypeCompatible('string', self::PROBE, 'name');
    }

    /**
     * Datetime precision zero fails the range guard.
     */
    public function testDatetimePrecisionZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'declares datetime precision [0], which is out of range',
        );

        (new Column(type: ColumnType::DateTime, precision: 0))
            ->assertTypeCompatible('string', self::PROBE, 'name');
    }

    /**
     * Fractional precision on a Unix-timestamp column is meaningless —
     * the dedicated throw.
     */
    public function testTimestampPrecisionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'declares precision on an int Unix-timestamp column; Unix timestamps are whole seconds',
        );

        (new Column(type: ColumnType::Timestamp, precision: 3))
            ->assertTypeCompatible('int', self::PROBE, 'count');
    }

    /**
     * resolvedEnumValues() rejects a non-enum values source.
     *
     * The runtime type of $values is array|string|null; the ctor docblock
     * narrows it to the two VALID shapes, so the guard's string arm is
     * only reachable with a deliberately mis-typed argument.
     */
    public function testResolvedEnumValuesRejectsNonEnum(): void
    {
        /** @phpstan-ignore argument.type (the mis-typed argument IS the test's subject) */
        $column = new Column(type: ColumnType::Enum, values: 'NotAnEnumAtAll');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'The enum column values source [NotAnEnumAtAll] is not an enum class-string.',
        );

        $column->resolvedEnumValues();
    }

    /**
     * resolvedEnumValues() rejects a case-less enum.
     */
    public function testResolvedEnumValuesRejectsEmptyEnum(): void
    {
        $column = new Column(type: ColumnType::Enum, values: EmptyEnumForCodec::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'The enum [' . EmptyEnumForCodec::class . '] declares no cases;'
                . ' an enum column needs at least one value.',
        );

        $column->resolvedEnumValues();
    }

    /**
     * resolvedEnumValues() maps a backed enum to its backing values; the
     * column length derives from the longest value during the
     * type-compatibility pass.
     */
    public function testResolvedEnumValuesMapsBackedEnum(): void
    {
        $column = new Column(type: ColumnType::Enum, values: CodecStatus::class);

        self::assertSame(['draft', 'published'], $column->resolvedEnumValues());

        $column->assertTypeCompatible('string', self::PROBE, 'name');

        self::assertSame(9, $column->length, 'the length derives from the longest case value');
    }

    /**
     * resolvedEnumValues() maps a unit enum to its case names.
     */
    public function testResolvedEnumValuesMapsUnitEnum(): void
    {
        $column = new Column(type: ColumnType::Enum, values: CodecKind::class);

        self::assertSame(['Small', 'Large'], $column->resolvedEnumValues());
    }

    /**
     * A divergent default whose declared value is an Expression renders
     * the expression's raw SQL in the throw.
     */
    public function testDefaultDivergenceRendersExpression(): void
    {
        $column = new Column(
            type: ColumnType::String,
            length: 8,
            default: new Expression("'x'"),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "declares a PHP default ['x'] that differs from the declared column default ['x']",
        );

        $column->assertDefaultConsistent(
            new \ReflectionProperty(CodecProbe::class, 'name'),
            CodecProbe::class,
        );
    }

    /**
     * A corrupt datetime cell fails loudly with the column named — the
     * RuntimeException wraps the parser error.
     */
    public function testCorruptDatetimeThrowsWithColumnName(): void
    {
        $column = new Column(type: ColumnType::DateTime, name: 'd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [d] could not decode the value ['garbage'] as a datetime:",
        );

        $column->decode('garbage', \Carbon\Carbon::class);
    }

    /**
     * A non-scalar datetime cell renders the debug type in the throw.
     */
    public function testCorruptDatetimeNonScalarRendersType(): void
    {
        $column = new Column(type: ColumnType::DateTime, name: 'd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [d] could not decode the value [array] as a datetime:',
        );

        $column->decode(['x'], \Carbon\Carbon::class);
    }

    /**
     * A Timestamp column decodes a datetime string as UTC wall-clock —
     * the int arm (the MySQL driver's storage form). The decoder writes
     * UTC and reads UTC, so the epoch integer survives regardless of the
     * host's `date.timezone`.
     */
    public function testTimestampDecodesDatetimeStringAsUtc(): void
    {
        $column = new Column(type: ColumnType::Timestamp);

        self::assertSame(
            1791203400,
            $column->decode('2026-10-05 12:30:00', 'int'),
        );
    }

    /**
     * A Timestamp column decodes a numeric cell through the cast — the
     * storage SQLite returns for its integer timestamp cells. Bare
     * timestamp digits would strtotime() to false (silently 0 on the
     * typed property).
     */
    public function testTimestampDecodesNumericCellThroughCast(): void
    {
        $column = new Column(type: ColumnType::Timestamp);

        self::assertSame(1791186433, $column->decode(1791186433, 'int'));
        self::assertSame(1791186433, $column->decode('1791186433', 'int'));
    }

    /**
     * The UTC decode contract holds on a non-UTC host: `date.timezone` is
     * an environment property, not an API contract, and the decoder that
     * parsed the UTC-written datetime string in the host zone drifted the
     * round-trip by the host's UTC offset. Every Timestamp arm runs inside
     * the shifted zone, restored unconditionally afterwards.
     */
    public function testTimestampRoundTripHoldsUnderNonUtcHostZone(): void
    {
        TimezoneSwap::under('Australia/Sydney', function (): void {
            $column = new Column(type: ColumnType::Timestamp);
            $ts = 1791203400;

            $stored = $column->encode($ts, 'int');

            self::assertSame('2026-10-05 12:30:00', $stored);
            self::assertSame($ts, $column->decode($stored, 'int'));

            $carbon = $column->decode($stored, \Carbon\Carbon::class);

            self::assertSame($ts, $carbon->getTimestamp());

            $immutable = $column->decode($stored, \DateTimeImmutable::class);

            self::assertSame($ts, $immutable->getTimestamp());
        });
    }

    /**
     * The DateTime column's decode holds the instant on a non-UTC host:
     * the codec normalized the binding to UTC wall-clock on the way in,
     * so the re-hydrated Carbon must carry that UTC interpretation rather
     * than silently inheriting `date.timezone`. A naive `Carbon::parse`
     * under Sydney reads 05:06:07 as Sydney wall-clock — a 39 600-second
     * drift this test pins shut.
     */
    public function testDateTimeDecodeHoldsInstantUnderNonUtcHostZone(): void
    {
        TimezoneSwap::under('Australia/Sydney', function (): void {
            $column = new Column(type: ColumnType::DateTime, name: 'fired_at');

            $decoded = $column->decode('2026-03-04 05:06:07', \Carbon\Carbon::class);

            self::assertInstanceOf(\Carbon\Carbon::class, $decoded);
            self::assertSame(1772600767, $decoded->getTimestamp());
        });
    }

    /**
     * The Date column's decode holds the calendar day on a non-UTC host:
     * a `Y-m-d` cell parsed in the host zone lands its midnight in
     * Sydney instead of UTC, shifting the instant by the offset. The
     * UTC-tagged start of day is pinned by epoch — bare-parsed under
     * Sydney it would read 1 767 272 400 instead.
     */
    public function testDateDecodeHoldsStartOfDayUnderNonUtcHostZone(): void
    {
        TimezoneSwap::under('Australia/Sydney', function (): void {
            $column = new Column(type: ColumnType::Date, name: 'd');

            $decoded = $column->decode('2026-01-02', \Carbon\Carbon::class);

            self::assertInstanceOf(\Carbon\Carbon::class, $decoded);
            self::assertSame(1767312000, $decoded->getTimestamp());
        });
    }

    /**
     * A Timestamp column fails loudly — with the column named — on a
     * non-numeric, unparseable cell instead of silently decoding to 0.
     */
    public function testTimestampGarbageCellThrowsWithColumnName(): void
    {
        $column = new Column(type: ColumnType::Timestamp, name: 'expires_at');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [expires_at] could not decode the value ['not-a-timestamp'] as a Unix timestamp.",
        );

        $column->decode('not-a-timestamp', 'int');
    }

    /**
     * The timestamp garbage throw names the literal 'timestamp' when the
     * column carries no name.
     */
    public function testTimestampGarbageCellUnnamedColumnFallsBackToLiteralName(): void
    {
        $column = new Column(type: ColumnType::Timestamp);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [timestamp] could not decode the value ['not-a-timestamp'] as a Unix timestamp.",
        );

        $column->decode('not-a-timestamp', 'int');
    }

    /**
     * A Timestamp column decodes a numeric cell to Carbon for a
     * datetime-typed property — Carbon::parse rejects bare timestamp
     * digits, so the numeric form must be reconstituted from the epoch.
     */
    public function testTimestampNumericCellDecodesToCarbon(): void
    {
        $column = new Column(type: ColumnType::Timestamp, name: 'fired_at');

        $decoded = $column->decode(1791186433, \Carbon\Carbon::class);

        self::assertInstanceOf(\Carbon\Carbon::class, $decoded);
        self::assertSame('2026-10-05 07:47:13', $decoded->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * A plain Int column decodes through the (int) cast — not strtotime.
     */
    public function testIntColumnDecodesThroughCast(): void
    {
        $column = new Column(type: ColumnType::Int);

        self::assertSame(42, $column->decode('42', 'int'));
    }

    /**
     * A Date column passes the stored Y-m-d cell through to a
     * string-typed property verbatim — the property type drives the
     * cast, so the property keeps the storage format it declared.
     */
    public function testDateStringPropertyPassesCellThrough(): void
    {
        $column = new Column(type: ColumnType::Date, name: 'd');

        self::assertSame('2026-01-02', $column->decode('2026-01-02', 'string'));
    }

    /**
     * A Date column decodes to Carbon for a DateTimeInterface-typed
     * property — pinned at start of day by the stored form.
     */
    public function testDateDecodesToStartOfDayForCarbonProperty(): void
    {
        $column = new Column(type: ColumnType::Date, name: 'd');

        $decoded = $column->decode('2026-01-02', \Carbon\Carbon::class);

        self::assertInstanceOf(\Carbon\Carbon::class, $decoded);
        self::assertSame('2026-01-02 00:00:00', $decoded->format('Y-m-d H:i:s'));
    }

    /**
     * A non-Date string column passes the value through untouched.
     */
    public function testNonDateStringColumnPassesThrough(): void
    {
        $column = new Column(type: ColumnType::String, length: 8);

        self::assertSame('raw', $column->decode('raw', 'string'));
    }

    /**
     * JSON encoding of a non-encodable float fails loudly with the column
     * named — the JsonException wrap.
     */
    public function testJsonEncodeFailureWrapsJsonException(): void
    {
        $column = new Column(type: ColumnType::Json, name: 'meta');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [meta] could not encode the value [float] as JSON:',
        );

        $column->encode(NAN, 'array');
    }

    /**
     * A malformed UUID fails the RFC 4122 guard.
     */
    public function testMalformedUuidThrows(): void
    {
        $column = new Column(type: ColumnType::Uuid);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [uuid] requires a valid RFC 4122 UUID; got [not-a-uuid].',
        );

        $column->encode('not-a-uuid', 'string');
    }

    /**
     * The all-zero UUID fails the guard — version and variant nibbles are
     * required.
     */
    public function testZeroUuidThrows(): void
    {
        $column = new Column(type: ColumnType::Uuid);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'requires a valid RFC 4122 UUID',
        );

        $column->encode('00000000-0000-0000-0000-000000000000', 'string');
    }

    /**
     * A valid v4 UUID passes the guard unchanged.
     */
    public function testValidUuidPassesThrough(): void
    {
        $column = new Column(type: ColumnType::Uuid);

        $uuid = '123e4567-e89b-42d3-a456-426614174000';

        self::assertSame($uuid, $column->encode($uuid, 'string'));
    }

    /**
     * A stored value that is not a case of the backed enum fails fast.
     */
    public function testDecodeUnknownBackedEnumCaseThrows(): void
    {
        $column = new Column(type: ColumnType::Enum, name: 'status');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [status] holds the value ['bogus'], which is not a case of the enum ["
                . CodecStatus::class . '].',
        );

        $column->decode('bogus', CodecStatus::class);
    }

    /**
     * A stored value that is not a case of the unit enum fails fast — the
     * unit-enum arm.
     */
    public function testDecodeUnknownUnitEnumCaseThrows(): void
    {
        $column = new Column(type: ColumnType::Enum, name: 'kind');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [kind] holds the value ['Nope'], which is not a case of the enum ["
                . CodecKind::class . '].',
        );

        $column->decode('Nope', CodecKind::class);
    }

    /**
     * An int-backed enum decodes a numeric-STRING cell — drivers that
     * stringify integer columns (CSV, emulated prepares) must not reach
     * the typed tryFrom() and TypeError instead of the named throw.
     */
    public function testDecodeIntBackedEnumFromNumericStringCell(): void
    {
        $column = new Column(type: ColumnType::Int, name: 'level');

        self::assertSame(CodecIntStatus::High, $column->decode('5', CodecIntStatus::class));
        self::assertSame(CodecIntStatus::Low, $column->decode(1, CodecIntStatus::class));
    }

    /**
     * A string-backed enum decodes a non-numeric int cell with the named
     * throw — never a TypeError from the typed tryFrom().
     */
    public function testDecodeStringBackedEnumFromIntCellThrows(): void
    {
        $column = new Column(type: ColumnType::String, length: 9, name: 'status');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [status] holds the value [0], which is not a case of the enum ['
                . CodecStatus::class . '].',
        );

        $column->decode(0, CodecStatus::class);
    }

    /**
     * An int-backed enum re-encodes an already-encoded numeric-string
     * value — the builder's write path may feed a stored cell back in.
     */
    public function testEncodeIntBackedEnumFromNumericString(): void
    {
        $column = new Column(type: ColumnType::Int, name: 'level');

        self::assertSame(5, $column->encode('5', CodecIntStatus::class));
    }

    /**
     * An int-backed enum rejects a non-numeric string with the named
     * throw — never a TypeError from the typed tryFrom().
     */
    public function testDecodeIntBackedEnumFromGarbageStringThrows(): void
    {
        $column = new Column(type: ColumnType::Int, name: 'level');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [level] holds the value ['bogus'], which is not a case of the enum ["
                . CodecIntStatus::class . '].',
        );

        $column->decode('bogus', CodecIntStatus::class);
    }

    /**
     * A non-scalar stored enum value fails on the unit-enum arm — the
     * name comparison renders the debug type in the throw. (The backed
     * arm TypeErrors first on a non-scalar: tryFrom() is typed.)
     */
    public function testDecodeNonScalarEnumValueRendersType(): void
    {
        $column = new Column(type: ColumnType::Enum, name: 'kind');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [kind] holds the value [array], which is not a case of the enum',
        );

        $column->decode(['x'], CodecKind::class);
    }

    /**
     * An enum column with NO values whose property type IS an enum class
     * fails the values guard — the class-string source is the only way to
     * derive values, and none were declared.
     */
    public function testEnumColumnWithoutValuesThrows(): void
    {
        $column = new Column(type: ColumnType::Enum);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'declares an enum column without values; declare `values:` with the allowed strings '
                . 'or an enum class-string.',
        );

        $column->assertTypeCompatible(CodecStatus::class, self::PROBE, 'name');
    }

    /**
     * An INTERFACE property type (even a JsonStorable one) hits the
     * objectCompatibility null arm — only concrete classes map to Json.
     */
    public function testInterfacePropertyTypeHasNoCompatibleColumns(): void
    {
        $column = new Column(type: ColumnType::Json);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Compatible column types for [' . \BlueprintAU\Radiant\Database\Query\JsonStorable::class . ']: none.',
        );

        $column->assertTypeCompatible(\BlueprintAU\Radiant\Database\Query\JsonStorable::class, self::PROBE, 'name');
    }

    /**
     * A property with a PHP default and NO declared column default is
     * consistent — nothing to shadow.
     */
    public function testDefaultConsistentWithoutColumnDefault(): void
    {
        $column = new Column(type: ColumnType::String, length: 8);

        // No throw — no column default, nothing to shadow.
        $column->assertDefaultConsistent(
            new \ReflectionProperty(CodecProbe::class, 'name'),
            CodecProbe::class,
        );

        self::assertNull($column->name);
    }

    /**
     * A float property decodes through the float cast.
     */
    public function testFloatColumnDecodesThroughCast(): void
    {
        $column = new Column(type: ColumnType::Float);

        self::assertSame(1.5, $column->decode('1.5', 'float'));
    }

    /**
     * A bool property decodes through the bool cast.
     */
    public function testBoolColumnDecodesThroughCast(): void
    {
        $column = new Column(type: ColumnType::Boolean);

        self::assertTrue($column->decode('1', 'bool'));
        self::assertFalse($column->decode('0', 'bool'));
    }

    /**
     * A Json column whose property type is a class but whose value is NOT
     * JsonSerializable fails fast — the encode guard.
     */
    public function testJsonEncodeRejectsNonSerializableObject(): void
    {
        $column = new Column(type: ColumnType::Json, name: 'meta');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [meta] expects a ' . \stdClass::class . ' value; got stdClass',
        );

        $column->encode(new \stdClass(), \stdClass::class);
    }

    /**
     * A Json column whose property type is a JsonStorable class encodes
     * the object through jsonSerialize.
     */
    public function testJsonEncodeSerializesStorableObject(): void
    {
        $column = new Column(type: ColumnType::Json, name: 'meta');

        $encoded = $column->encode(new UserPreferences(), UserPreferences::class);

        self::assertIsString($encoded);
        self::assertStringContainsString('"theme":"dark"', $encoded);
    }

    /**
     * A Date column encodes a datetime to the UTC calendar day — the
     * `Y-m-d` arm.
     */
    public function testDateColumnEncodesToUtcCalendarDay(): void
    {
        $column = new Column(type: ColumnType::Date, name: 'day');

        $encoded = $column->encode(
            new \DateTimeImmutable('2026-10-02 23:30:00', new \DateTimeZone('Europe/Berlin')),
            'datetime',
        );

        // 23:30 Berlin = 21:30 UTC the same day — the calendar day holds.
        self::assertSame('2026-10-02', $encoded);
    }
}
