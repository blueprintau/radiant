<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\ValueCodecs;

use BlueprintAU\Radiant\Database\ValueCodecs\DefaultValueCodec;
use BlueprintAU\Radiant\Database\ValueCodecs\PostgresValueCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the value codecs — pure encode/decode, data-provider driven.
 */
final class ValueCodecTest extends TestCase
{
    /**
     * Encode passes scalars through untouched and formats datetimes.
     *
     * @param string|int|float|bool|null|\DateTimeInterface $value The input.
     * @param string|int|float|bool|null $expected The expected output.
     */
    #[DataProvider('encodeProvider')]
    public function testEncode(string|int|float|bool|null|\DateTimeInterface $value, string|int|float|bool|null $expected): void
    {
        self::assertSame($expected, (new DefaultValueCodec())->encode($value));
    }

    /**
     * @return iterable<string, array{0: string|int|float|bool|null|\DateTimeInterface, 1: string|int|float|bool|null}>
     */
    public static function encodeProvider(): iterable
    {
        yield 'int' => [42, 42];
        yield 'float' => [3.14, 3.14];
        yield 'string' => ['hello', 'hello'];
        yield 'bool' => [true, true];
        yield 'null' => [null, null];
        yield 'datetime formatted UTC' => [new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC')), '2024-01-02 03:04:05'];
        yield 'datetime normalized to UTC' => [new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('Europe/Berlin')), '2024-01-02 02:04:05'];
        yield 'mutable DateTime accepted' => [new \DateTime('2024-06-07 08:09:10', new \DateTimeZone('UTC')), '2024-06-07 08:09:10'];
    }

    /**
     * The caller's DateTime object is NEVER mutated by encode — the
     * timezone normalization happens on a fresh immutable copy.
     */
    public function testEncodeDoesNotMutateInput(): void
    {
        $input = new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('Europe/Berlin'));
        $before = $input->format('Y-m-d H:i:s e');

        (new DefaultValueCodec())->encode($input);

        self::assertSame($before, $input->format('Y-m-d H:i:s e'));
    }

    /**
     * Constructor tuning: a custom format and timezone apply on encode.
     */
    public function testCodecConstructorTuning(): void
    {
        $codec = new DefaultValueCodec('d/m/Y H:i', 'America/New_York');
        $value = new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC'));

        self::assertSame('01/01/2024 22:04', $codec->encode($value));
    }

    /**
     * The Postgres codec formats datetimes with microsecond precision.
     */
    public function testPostgresMicroseconds(): void
    {
        $value = new \DateTimeImmutable('2024-01-02 03:04:05.123456', new \DateTimeZone('UTC'));
        self::assertSame('2024-01-02 03:04:05.123456', (new PostgresValueCodec())->encode($value));
    }

    /**
     * The Postgres codec inherits scalar passthrough — only datetime
     * formatting is overridden (regression context: boolean overrides were
     * removed because a varchar holding literal 't' decoded as bool).
     */
    public function testPostgresCodecPassesScalarsThrough(): void
    {
        $codec = new PostgresValueCodec();

        self::assertSame('t', $codec->decode('t'), 'a literal t-string must NOT decode to bool');
        self::assertSame(42, $codec->decode(42));
        self::assertSame(null, $codec->decode(null));
    }

    /**
     * Decode is identity — driver values pass through untouched.
     *
     * @param string|int|float|bool|null $value The input.
     */
    #[DataProvider('decodeProvider')]
    public function testDecode(string|int|float|bool|null $value): void
    {
        self::assertSame($value, (new DefaultValueCodec())->decode($value));
    }

    /**
     * @return iterable<string, array{0: string|int|float|bool|null}>
     */
    public static function decodeProvider(): iterable
    {
        yield 'int' => [42];
        yield 'float' => [3.14];
        yield 'string' => ['hello'];
        yield 'bool' => [true];
        yield 'null' => [null];
    }
}