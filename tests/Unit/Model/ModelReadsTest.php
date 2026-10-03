<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BpUser;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfUser;

/**
 * The static read family on Model: first/count/exists, the scalar reads,
 * the aggregates, and the cursor — every one a forwarder over newQuery().
 */
final class ModelReadsTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed two users (one with a signup
     * timestamp, one without).
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(BpUser::class, OfUser::class);

        $user = new BpUser();
        $user->name = 'ada';
        $user->signedUpAt = \Carbon\Carbon::parse('2026-01-15 10:00:00');
        $user->meta = ['theme' => 'dark'];
        $user->save();

        $second = new BpUser();
        $second->name = 'ben';
        $second->meta = ['theme' => 'light'];
        $second->save();
    }

    /**
     * first() returns the first model of the table.
     */
    public function testFirstReturnsFirstModel(): void
    {
        $user = BpUser::first();

        self::assertInstanceOf(BpUser::class, $user);
        self::assertSame('ada', $user->name);
    }

    /**
     * count()/exists() reflect the table's contents.
     */
    public function testCountAndExists(): void
    {
        self::assertSame(2, BpUser::count());
        self::assertTrue(BpUser::exists());
        self::assertSame(0, OfUser::count());
        self::assertFalse(OfUser::exists());
    }

    /**
     * value() decodes through the column's cast.
     */
    public function testValueDecodes(): void
    {
        self::assertSame('ada', BpUser::value('name'));
    }

    /**
     * pluck() decodes every value through the casts.
     */
    public function testPluckDecodes(): void
    {
        self::assertSame(['ada', 'ben'], BpUser::pluck('name')->all());
    }

    /**
     * max() decodes a datetime column to Carbon.
     */
    public function testMaxDecodesToCarbon(): void
    {
        $max = BpUser::max('signed_up_at');

        self::assertInstanceOf(\Carbon\Carbon::class, $max);
        self::assertEquals(\Carbon\Carbon::parse('2026-01-15 10:00:00'), $max);
    }

    /**
     * aggregates() returns the multi-aggregate row keyed by alias.
     */
    public function testAggregatesReturnsTheRow(): void
    {
        $row = BpUser::aggregates(
            Aggregate::count('*', 'total'),
            Aggregate::max('signed_up_at', 'latest'),
        );

        self::assertSame(2, $row->total);
        self::assertEquals(\Carbon\Carbon::parse('2026-01-15 10:00:00'), $row->latest);
    }

    /**
     * countBy() groups the rows per column with int counts.
     */
    public function testCountByGroups(): void
    {
        self::assertSame(['ada' => 1, 'ben' => 1], BpUser::countBy('name')->all());
    }

    /**
     * aggregateBy() decodes a declared column's cast per group.
     */
    public function testAggregateByDecodes(): void
    {
        $byName = BpUser::aggregateBy(Aggregate::max('signed_up_at'), 'name');

        self::assertEquals(\Carbon\Carbon::parse('2026-01-15 10:00:00'), $byName['ada']);
        self::assertNull($byName['ben']);
    }

    /**
     * cursor() streams the models.
     */
    public function testCursorYieldsModels(): void
    {
        $names = [];

        foreach (BpUser::cursor() as $user) {
            $names[] = $user->name;
        }

        self::assertSame(['ada', 'ben'], $names);
    }
}
