<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\CastSafety;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Inspectors\MariaDbSchemaInspector;
use PHPUnit\Framework\TestCase;

/**
 * The MariaDB inspector divergences over the MySQL mapping: JSON reads
 * back as `longtext`, and a current-timestamp default normalizes to
 * MySQL's `CURRENT_TIMESTAMP` spelling.
 */
final class MariaDbSchemaInspectorTest extends TestCase
{
    /** @var MariaDbSchemaInspector The inspector under test. */
    private MariaDbSchemaInspector $inspector;

    /**
     * Build the inspector over a throwaway PDO — the inspector's type
     * comparisons never touch the connection.
     */
    protected function setUp(): void
    {
        $this->inspector = new MariaDbSchemaInspector(new \PDO('sqlite::memory:'));
    }

    /**
     * A declared Json column matches a live longtext — MariaDB stores
     * JSON as LONGTEXT.
     */
    public function testLongtextMatchesJson(): void
    {
        self::assertTrue(
            $this->inspector->columnTypeMatches('longtext', ColumnType::Json, null),
        );
    }

    /**
     * A plain longtext still does NOT match a declared Text column — the
     * alias is one-way: MariaDB spells a Text column `longtext` too, but
     * the declared Json renders `json` on MySQL, so the drift only ever
     * runs Json-declared against longtext-live.
     */
    public function testLongtextDoesNotMatchText(): void
    {
        self::assertFalse(
            $this->inspector->columnTypeMatches('longtext', ColumnType::Text, null),
        );
    }

    /**
     * A live `json` type (possible on MariaDB via an explicit DDL) still
     * matches a declared Json column.
     */
    public function testJsonStillMatchesJson(): void
    {
        self::assertTrue(
            $this->inspector->columnTypeMatches('json', ColumnType::Json, null),
        );
    }

    /**
     * MySQL's own type spellings keep matching — the MySQL mapping is
     * inherited, not replaced.
     */
    public function testMySqlMappingsAreInherited(): void
    {
        self::assertTrue(
            $this->inspector->columnTypeMatches('int', ColumnType::Int, null),
        );
        self::assertTrue(
            $this->inspector->columnTypeMatches('varchar(100)', ColumnType::String, 100),
        );
        self::assertFalse(
            $this->inspector->columnTypeMatches('int', ColumnType::BigInt, null),
        );
    }

    /**
     * A longtext column converts to Json safely — MariaDB's own JSON
     * storage IS a checked longtext.
     */
    public function testLongtextToJsonCastsSafely(): void
    {
        self::assertSame(
            CastSafety::Safe,
            $this->inspector->castSafety('longtext', ColumnType::Json),
        );
    }

    /**
     * Everything else keeps the inherited lenient classification.
     */
    public function testOtherCastsStayLenient(): void
    {
        self::assertSame(
            CastSafety::Safe,
            $this->inspector->castSafety('int', ColumnType::BigInt),
        );
        self::assertSame(
            CastSafety::Risky,
            $this->inspector->castSafety('longtext', ColumnType::Int),
        );
    }

    /**
     * MariaDB's `current_timestamp()` and `now()` default spellings
     * normalize to MySQL's `CURRENT_TIMESTAMP`.
     *
     * @return iterable<string, array{string}> The spellings.
     */
    public static function currentTimestampSpellingsProvider(): iterable
    {
        yield 'current_timestamp()' => ['current_timestamp()'];
        yield 'CURRENT_TIMESTAMP()' => ['CURRENT_TIMESTAMP()'];
        yield 'now()' => ['now()'];
        yield 'padded now()' => [' now() '];
    }

    /**
     * Every current-timestamp spelling normalizes to CURRENT_TIMESTAMP.
     *
     * @param string $spelling The live default text.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('currentTimestampSpellingsProvider')]
    public function testCurrentTimestampSpellingsNormalize(string $spelling): void
    {
        $method = new \ReflectionMethod(MariaDbSchemaInspector::class, 'normalizeColumnDefault');

        self::assertSame('CURRENT_TIMESTAMP', $method->invoke($this->inspector, $spelling));
    }

    /**
     * Other defaults pass through untouched — only the spelling of the
     * current-timestamp function is normalized.
     */
    public function testOtherDefaultsPassThrough(): void
    {
        $method = new \ReflectionMethod(MariaDbSchemaInspector::class, 'normalizeColumnDefault');

        self::assertSame("'hello'", $method->invoke($this->inspector, "'hello'"));
        self::assertSame('7', $method->invoke($this->inspector, '7'));
        self::assertNull($method->invoke($this->inspector, null));
        self::assertSame(42, $method->invoke($this->inspector, 42));
    }
}
