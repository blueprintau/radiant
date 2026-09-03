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
    }

    /**
     * Postgres codec formats datetimes with microsecond precision.
     */
    public function testPostgresMicroseconds(): void
    {
        $value = new \DateTimeImmutable('2024-01-02 03:04:05.123456', new \DateTimeZone('UTC'));
        self::assertSame('2024-01-02 03:04:05.123456', (new PostgresValueCodec())->encode($value));
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