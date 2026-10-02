<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\CodecKind;
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
     * A Timestamp column decodes a datetime string through strtotime —
     * the int arm.
     */
    public function testTimestampDecodesDatetimeStringThroughStrtotime(): void
    {
        $column = new Column(type: ColumnType::Timestamp);

        self::assertSame(
            strtotime('2026-01-02 03:04:05'),
            $column->decode('2026-01-02 03:04:05', 'int'),
        );
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
     * A Date column decodes a Y-m-d string to Carbon at start of day.
     */
    public function testDateDecodesToStartOfDay(): void
    {
        $column = new Column(type: ColumnType::Date, name: 'd');

        $decoded = $column->decode('2026-01-02', 'string');

        self::assertInstanceOf(\Carbon\Carbon::class, $decoded);
        self::assertSame('2026-01-02 00:00:00', $decoded->format('Y-m-d H:i:s'));
    }

    /**
     * A corrupt date cell fails loudly with the column named.
     */
    public function testCorruptDateThrowsWithColumnName(): void
    {
        $column = new Column(type: ColumnType::Date, name: 'd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [d] could not decode the value ['not-a-date'] as a date:",
        );

        $column->decode('not-a-date', 'string');
    }

    /**
     * A corrupt date on an unnamed column falls back to the literal
     * 'date' name in the throw.
     */
    public function testCorruptDateUnnamedColumnFallsBackToLiteralName(): void
    {
        $column = new Column(type: ColumnType::Date);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [date] could not decode the value ['not-a-date'] as a date:",
        );

        $column->decode('not-a-date', 'string');
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
