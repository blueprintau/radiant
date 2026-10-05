<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\ModelIntrospection;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CastProbe;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CastStatus;
use Carbon\Carbon;

/**
 * Save → re-fetch round-trips across the (ColumnType × property-type)
 * cast matrix on a live SQLite database — the hydration seam where the
 * decode half was silently lossy (an int property on a timestamp column
 * read back as 0, a string property on a date column read back as a
 * full datetime string).
 */
final class CastRoundTripTest extends DatabaseTestCase
{
    /**
     * Build the probe table straight from the fixture's metadata — the
     * attribute declarations ARE the schema contract under test.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(CastProbe::class);
    }

    /**
     * Save one row with every cast arm populated — the shared subject
     * of the round-trip tests.
     *
     * @return CastProbe The saved model (id assigned).
     */
    private function seedRow(): CastProbe
    {
        $probe = new CastProbe();
        $probe->stringTs = '2026-01-02 03:04:05';
        $probe->intTs = 1791186433;
        $probe->carbonTs = Carbon::createFromTimestamp(1791186433, 'UTC');
        $probe->dtTs = new \DateTime('2026-03-04 05:06:07');
        $probe->stringDate = '1990-06-15';
        $probe->carbonDate = Carbon::parse('1995-11-30', 'UTC');
        $probe->flag = true;
        $probe->ratio = 2.75;
        $probe->meta = ['theme' => 'dark', 'tabs' => [1, 2]];
        $probe->status = CastStatus::Published;
        $probe->token = '123e4567-e89b-42d3-a456-426614174000';
        $probe->save();

        return $probe;
    }

    /**
     * A row carrying every cast arm saves, reloads by primary key, and
     * every property reads back the exact value it was saved with.
     */
    public function testEveryCastArmRoundTrips(): void
    {
        $probe = $this->seedRow();

        $fresh = CastProbe::find($probe->id);

        self::assertNotNull($fresh);

        self::assertSame('2026-01-02 03:04:05', $fresh->stringTs);
        self::assertSame(1791186433, $fresh->intTs);
        self::assertSame('2026-10-05 07:47:13', $fresh->carbonTs->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-04 05:06:07', $fresh->dtTs->format('Y-m-d H:i:s'));
        self::assertSame('1990-06-15', $fresh->stringDate);
        self::assertSame('1995-11-30 00:00:00', $fresh->carbonDate->format('Y-m-d H:i:s'));
        self::assertTrue($fresh->flag);
        self::assertSame(2.75, $fresh->ratio);
        self::assertSame(['theme' => 'dark', 'tabs' => [1, 2]], $fresh->meta);
        self::assertSame(CastStatus::Published, $fresh->status);
        self::assertSame('123e4567-e89b-42d3-a456-426614174000', $fresh->token);
    }

    /**
     * The nullable timestamp arms survive the full null → set → null
     * rewrite cycle without corrupting either pole.
     */
    public function testNullableTimestampArmsRoundTripThroughNull(): void
    {
        $probe = $this->seedRow();

        $probe->nullableIntTs = null;
        $probe->nullableCarbonTs = null;
        $probe->save();

        $fresh = CastProbe::find($probe->id);

        self::assertNotNull($fresh);
        self::assertNull($fresh->nullableIntTs);
        self::assertNull($fresh->nullableCarbonTs);

        $fresh->nullableIntTs = 1700000000;
        $fresh->nullableCarbonTs = Carbon::parse('2027-08-09 10:11:12', 'UTC');
        $fresh->save();

        $refetched = CastProbe::find($probe->id);

        self::assertNotNull($refetched);
        self::assertNotNull($refetched->nullableCarbonTs);
        self::assertSame(1700000000, $refetched->nullableIntTs);
        self::assertSame('2027-08-09 10:11:12', $refetched->nullableCarbonTs->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * The re-fetched int timestamp reads back the exact stored epoch
     * second — bare unix-timestamp digits strtotime() to false, and the
     * reflected assignment coerced that into 0 before the fix.
     */
    public function testIntTimestampSurvivesReload(): void
    {
        $probe = $this->seedRow();

        $fresh = CastProbe::find($probe->id);

        self::assertNotNull($fresh);
        self::assertSame(1791186433, $fresh->intTs, 'the stored epoch second must survive hydration');
    }

    /**
     * A freshly hydrated model reports NO dirty columns — a decode that
     * hands the property a different shape than the encoder stores (a
     * Carbon for a string-typed date slot) would make `getDirty()` flag
     * the column on every hydration and write spurious UPDATEs.
     */
    public function testHydratedProbeIsNotSpuriouslyDirty(): void
    {
        $probe = $this->seedRow();

        $fresh = CastProbe::find($probe->id);

        self::assertNotNull($fresh);
        self::assertSame([], ModelIntrospection::dirtyOf($fresh));
    }

    /**
     * The write-path update of one timestamp arm keeps the other arms
     * byte-identical — the double-encode idempotence of the write path.
     */
    public function testUpdatingOneTimestampKeepsOthersUntouched(): void
    {
        $probe = $this->seedRow();

        $fresh = CastProbe::find($probe->id);

        self::assertNotNull($fresh);
        $fresh->intTs = 1800000000;
        $fresh->save();

        $refetched = CastProbe::find($probe->id);

        self::assertNotNull($refetched);
        self::assertSame(1800000000, $refetched->intTs);
        self::assertSame('2026-01-02 03:04:05', $refetched->stringTs);
        self::assertSame('2026-10-05 07:47:13', $refetched->carbonTs->utc()->format('Y-m-d H:i:s'));
        self::assertSame('1990-06-15', $refetched->stringDate);
    }

    /**
     * The raw stored cell keeps the encoder's form — an integer for the
     * int-typed timestamp (no format change for rows written before the
     * fix), a Y-m-d string for the date column.
     */
    public function testStoredCellFormatIsUnchanged(): void
    {
        $probe = $this->seedRow();

        $raw = $this->connection->table('cast_probes')->where('id', '=', $probe->id)->first();

        self::assertNotNull($raw);
        self::assertSame(1791186433, $raw->int_ts);
        self::assertSame('1990-06-15', $raw->string_date);
        self::assertSame('2026-01-02 03:04:05', $raw->string_ts);
    }

    /**
     * Builder scalar reads decode through the same casts as hydration —
     * `value()` on the int-timestamp column returns the epoch integer
     * (the DB column name addresses the column), not strtotime()'s false.
     */
    public function testScalarReadsDecodeThroughTheCasts(): void
    {
        $probe = $this->seedRow();

        self::assertSame(1791186433, CastProbe::value('int_ts'));
        self::assertSame('2026-01-02 03:04:05', CastProbe::value('string_ts'));

        $pluck = CastProbe::newQuery()->pluck('int_ts');

        self::assertSame([1791186433], $pluck->all());
    }

    /**
     * An insert through the builder with already-encoded values (raw
     * cells) hydrates correctly — the decode half accepts both the
     * model-encoded form and the driver's form.
     */
    public function testBuilderInsertOfRawCellsHydrates(): void
    {
        $inserted = CastProbe::newQuery()->insert([
            [
                'string_ts' => '2026-01-02 03:04:05',
                'int_ts' => 1791186433,
                'carbon_ts' => '2026-10-05 07:47:13',
                'dt_ts' => '2026-03-04 05:06:07',
                'string_date' => '1990-06-15',
                'carbon_date' => '1995-11-30',
                'flag' => 1,
                'ratio' => 2.75,
                'status' => 'published',
                'token' => '123e4567-e89b-42d3-a456-426614174000',
            ],
        ]);

        self::assertSame(1, $inserted);

        $fresh = CastProbe::sole();

        self::assertSame(1791186433, $fresh->intTs);
        self::assertSame('1990-06-15', $fresh->stringDate);
        self::assertSame('2026-01-02 03:04:05', $fresh->stringTs);
    }

    /**
     * The where filter binds the epoch integer against the stored cell —
     * the row saved by the model's encoder matches.
     */
    public function testWhereFiltersByDecodedTimestamp(): void
    {
        $probe = $this->seedRow();

        $matched = CastProbe::newQuery()->where('int_ts', '=', 1791186433)->get();

        self::assertCount(1, $matched);
        self::assertSame(1791186433, $matched->first()?->intTs);
    }
}
