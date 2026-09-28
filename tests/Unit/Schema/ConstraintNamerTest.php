<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\ConstraintNamer;
use PHPUnit\Framework\TestCase;

/**
 * {@see ConstraintNamer::derive()} — the shared
 * `{table}_{columns}_{suffix}` naming convention, plus its fail-fast
 * validation: an empty table, an empty suffix, an empty column list, or
 * an empty column NAME inside the list each reject at derivation.
 */
final class ConstraintNamerTest extends TestCase
{
    /**
     * The happy path: table, columns, and suffix join with underscores.
     */
    public function testDerivesTableColumnsSuffix(): void
    {
        self::assertSame(
            'users_regionId_country_unique',
            ConstraintNamer::derive('users', ['regionId', 'country'], 'unique'),
        );
    }

    /**
     * A single-column index name derives the same way — columns is a list.
     */
    public function testDerivesSingleColumnIndex(): void
    {
        self::assertSame(
            'posts_user_id_index',
            ConstraintNamer::derive('posts', ['user_id'], 'index'),
        );
    }

    /**
     * An empty table name rejects with the combined validation message.
     */
    public function testEmptyTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A derived constraint name requires a non-empty table, suffix, and columns; '
            . "got table [], suffix [unique], columns [regionId].",
        );

        ConstraintNamer::derive('', ['regionId'], 'unique');
    }

    /**
     * An empty suffix rejects.
     */
    public function testEmptySuffixThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A derived constraint name requires a non-empty table, suffix, and columns; '
            . 'got table [users], suffix [], columns [regionId].',
        );

        ConstraintNamer::derive('users', ['regionId'], '');
    }

    /**
     * An empty column list rejects.
     */
    public function testEmptyColumnListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A derived constraint name requires a non-empty table, suffix, and columns; '
            . 'got table [users], suffix [unique], columns [].',
        );

        ConstraintNamer::derive('users', [], 'unique');
    }

    /**
     * An empty NAME inside the column list rejects — a blank member would
     * render a doubled underscore and an ambiguous constraint.
     */
    public function testEmptyColumnNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A derived constraint name requires a non-empty table, suffix, and columns; '
            . 'got table [users], suffix [unique], columns [regionId, ].',
        );

        ConstraintNamer::derive('users', ['regionId', ''], 'unique');
    }
}
