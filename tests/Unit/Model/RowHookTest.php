<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Exceptions\RowHookVetoException;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\ColumnAdder;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\PlainTimestamped;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\RowHookProbe;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\UpdateHookProbe;
use Carbon\Carbon;

/**
 * End-to-end behavior of the bulk `#[RowHook]` path — dispatch from the
 * builder's insert/insertGetId/update, Timestamps bulk stamping, veto
 * semantics, and the value space hooks run in.
 */
final class RowHookTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables from their attributes.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            RowHookProbe::class,
            PlainTimestamped::class,
            UpdateHookProbe::class,
            ColumnAdder::class,
        );

        RowHookProbe::resetProbes();
        UpdateHookProbe::$seenUpdateValues = null;
    }

    /**
     * A bulk insert is stamped by the Timestamps RowHook — both columns,
     * one clock read per batch, Carbon in the hook's value space.
     */
    public function testBulkInsertStampsBothColumnsWithOneClockRead(): void
    {
        RowHookProbe::newQuery()->insert([
            ['name' => 'ada'],
            ['name' => 'ben'],
        ]);

        $rows = RowHookProbe::newQuery()->orderBy('id')->getRaw()->all();

        self::assertCount(2, $rows);
        self::assertSame('2026-06-01 12:00:00', $rows[0]->created_at);
        self::assertSame('2026-06-01 12:00:00', $rows[0]->updated_at);
        self::assertSame('2026-06-01 12:00:00', $rows[1]->created_at);
        self::assertSame('2026-06-01 12:00:00', $rows[1]->updated_at);
        self::assertSame(1, RowHookProbe::$clockReads, 'one clock read per batch, not per row');
    }

    /**
     * A caller-set created_at survives the bulk stamp — the caller's
     * value always wins, per column.
     */
    public function testBulkInsertCallerSetValueWins(): void
    {
        RowHookProbe::newQuery()->insert([
            ['name' => 'ada', 'created_at' => Carbon::parse('2020-01-01 00:00:00')],
            ['name' => 'ben'],
        ]);

        $rows = RowHookProbe::newQuery()->orderBy('id')->getRaw()->all();

        self::assertSame('2020-01-01 00:00:00', $rows[0]->created_at);
        self::assertSame('2026-06-01 12:00:00', $rows[0]->updated_at, 'unstamped columns still stamp');
        self::assertSame('2026-06-01 12:00:00', $rows[1]->created_at);
    }

    /**
     * insertGetId() dispatches the insert hooks as a one-row batch.
     */
    public function testInsertGetIdStampsAsOneRowBatch(): void
    {
        $id = RowHookProbe::newQuery()->insertGetId(['name' => 'ada']);

        $row = RowHookProbe::newQuery()->whereKey($id)->first();

        self::assertNotNull($row);
        self::assertSame('2026-06-01 12:00:00', $row->attribute('created_at')->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-01 12:00:00', $row->attribute('updated_at')->format('Y-m-d H:i:s'));
        self::assertSame(1, RowHookProbe::$clockReads);
    }

    /**
     * A bulk update is stamped — updated_at bumps when the payload does
     * not carry it.
     */
    public function testBulkUpdateStampsUpdatedAt(): void
    {
        $id = PlainTimestamped::newQuery()->insertGetId(['name' => 'ada']);

        PlainTimestamped::newQuery()->whereKey($id)->update(['name' => 'renamed']);

        $row = PlainTimestamped::newQuery()->whereKey($id)->first();

        self::assertNotNull($row);
        self::assertSame('renamed', $row->name);
        self::assertSame('2026-06-01 12:00:00', $row->attribute('updated_at')->format('Y-m-d H:i:s'));
    }

    /**
     * A caller-set updated_at survives the bulk update stamp.
     */
    public function testBulkUpdateCallerSetValueWins(): void
    {
        $id = PlainTimestamped::newQuery()->insertGetId(['name' => 'ada']);

        PlainTimestamped::newQuery()->whereKey($id)->update([
            'name' => 'renamed',
            'updated_at' => Carbon::parse('2021-01-01 00:00:00'),
        ]);

        $row = PlainTimestamped::newQuery()->whereKey($id)->first();

        self::assertNotNull($row);
        self::assertSame('2021-01-01 00:00:00', $row->attribute('updated_at')->format('Y-m-d H:i:s'));
    }

    /**
     * The update hook receives the values map in the PHP value space —
     * before encoding, so a Carbon arrives as a Carbon.
     */
    public function testUpdateHookSeesPreEncodeValues(): void
    {
        $id = UpdateHookProbe::newQuery()->insertGetId(['name' => 'ada']);

        UpdateHookProbe::newQuery()->whereKey($id)->update([
            'name' => 'renamed',
            'updated_at' => Carbon::parse('2022-02-02 08:30:00'),
        ]);

        $seen = UpdateHookProbe::$seenUpdateValues;

        self::assertIsArray($seen);
        self::assertSame('renamed', $seen['name']);
        self::assertInstanceOf(Carbon::class, $seen['updated_at']);
        self::assertSame('2022-02-02 08:30:00', $seen['updated_at']->format('Y-m-d H:i:s'));
    }

    /**
     * A `false` return from a hook vetoes the whole batch — the veto
     * exception names trait::method and NO row was written.
     */
    public function testVetoThrowsAndWritesNothing(): void
    {
        try {
            RowHookProbe::newQuery()->insert([
                ['name' => 'ada'],
                ['name' => 'veto'],
            ]);

            self::fail('the vetoed insert must throw');
        } catch (RowHookVetoException $exception) {
            self::assertStringContainsString(
                Fixtures\VetoInsertRowsTrait::class.'::observeInsertRows',
                $exception->getMessage(),
            );
        }

        self::assertSame([], RowHookProbe::newQuery()->get()->all(), 'no row may survive a vetoed batch');
    }

    /**
     * The insert hook sees the full row list — even for a single-map
     * insert, normalized to a one-row batch. Timestamps stamps first, so
     * the recorded row already carries the stamp columns.
     */
    public function testInsertHookSeesNormalizedRowList(): void
    {
        RowHookProbe::newQuery()->insert(['name' => 'ada']);

        $seen = RowHookProbe::$seenInsertRows;

        self::assertCount(1, $seen, 'a single-map insert arrives as a one-row batch');
        self::assertSame('ada', $seen[0]['name']);
        self::assertArrayHasKey('created_at', $seen[0], 'the hook runs after the Timestamps stamper');
        self::assertInstanceOf(\Carbon\Carbon::class, $seen[0]['created_at']);
    }

    /**
     * A hook may ADD a column key to the rows — the added key is
     * validated and encoded like a caller-provided one.
     */
    public function testHookMayAddColumnKeys(): void
    {
        ColumnAdder::newQuery()->insert([
            ['name' => 'ada'],
            ['name' => 'ben'],
        ]);

        $rows = ColumnAdder::newQuery()->orderBy('id')->getRaw()->all();

        self::assertSame(10, $rows[0]->rank);
        self::assertSame(20, $rows[1]->rank);
    }

    /**
     * save() stamps exactly once — the instance WriteHook stamps the
     * payload and the bulk RowHook sees the columns already present.
     */
    public function testSaveStampsExactlyOnce(): void
    {
        $model = new RowHookProbe();
        $model->name = 'ada';
        $model->save();

        self::assertSame(1, RowHookProbe::$clockReads, 'the bulk stamper must not re-stamp a save() payload');

        $row = RowHookProbe::newQuery()->whereKey($model->getKeyForRefresh())->first();

        self::assertNotNull($row);
        self::assertSame('2026-06-01 12:00:00', $row->attribute('created_at')->format('Y-m-d H:i:s'));
    }

    /**
     * A RowHook without a bool return is a pure observer — a void hook
     * never vetoes.
     */
    public function testVoidHookDoesNotVeto(): void
    {
        ColumnAdder::newQuery()->insert(['name' => 'void']);

        self::assertSame(
            1,
            ColumnAdder::newQuery()->where('name', '=', 'void')->count(),
        );
    }

    /**
     * A model without Timestamps or RowHooks passes through the dispatch
     * untouched — the common case stays a no-op.
     */
    public function testModelWithoutHooksIsUntouched(): void
    {
        $count = PlainTimestamped::newQuery()->insert([
            ['name' => 'x'],
            ['name' => 'y'],
        ]);

        self::assertSame(2, $count);
        self::assertCount(2, PlainTimestamped::newQuery()->get());
    }

    /**
     * Reset the static probe state after each test.
     */
    protected function tearDown(): void
    {
        RowHookProbe::resetProbes();
        UpdateHookProbe::$seenUpdateValues = null;

        parent::tearDown();
    }
}
