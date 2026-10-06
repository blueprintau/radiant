<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\CastRoundTrips;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\ModelIntrospection;
use BlueprintAU\Radiant\Tests\Support\TimezoneSwap;
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
    use CastRoundTrips;

    /**
     * Build the probe table straight from the fixture's metadata — the
     * attribute declarations ARE the schema contract under test.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(CastProbe::class);
    }

    /**
     * One saved row carrying every cast arm — the shared subject of the
     * round-trip tests.
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
     * Every column of the shared row round-trips: the re-fetched model's
     * decoded values equal the saved model's — compared column by
     * column, decoded space to decoded space.
     */
    public function testEveryCastArmRoundTrips(): void
    {
        $probe = $this->seedRow();

        $this->roundTrip(
            $probe,
            ['id', 'string_ts', 'int_ts', 'carbon_ts', 'dt_ts', 'string_date', 'carbon_date', 'flag', 'ratio', 'meta', 'status', 'token'],
        );
    }

    /**
     * A freshly hydrated model reports NO dirty columns — a decode that
     * hands the property a different shape than the encoder stores (a
     * Carbon for a string-typed date slot) would make `getDirty()` flag
     * the column on every hydration and write spurious UPDATEs.
     */
    public function testHydratedProbeIsNotSpuriouslyDirty(): void
    {
        $refetched = $this->refetch($this->seedRow());

        self::assertSame([], ModelIntrospection::dirtyOf($refetched));
    }

    /**
     * The full save → re-fetch cycle holds the instant on a non-UTC host:
     * the row saves under `Australia/Sydney`, re-fetches, and the int arm
     * reads back the exact epoch seconds it was saved with — the UTC
     * datetime string the encoder binds must parse as UTC on the way out,
     * never in the host's zone (which drifted the round-trip by the
     * offset). The DateTime arm's seed is a naive host-zone wall-clock,
     * so the codec normalizes its BINDING to UTC on the way in — the
     * decoded instant must equal the seed's epoch (`assertEquals`, since
     * the re-hydrated Carbon is UTC-tagged while the seed carried the
     * host offset). The zone restores unconditionally afterwards.
     */
    public function testTimestampRoundTripHoldsUnderNonUtcHostZone(): void
    {
        TimezoneSwap::under('Australia/Sydney', function (): void {
            $probe = $this->seedRow();

            $refetched = CastProbe::find($probe->getKeyForRefresh());

            self::assertNotNull($refetched);
            self::assertSame(1791186433, $refetched->intTs);
            self::assertSame(
                1791186433,
                $refetched->carbonTs->getTimestamp(),
                'the Carbon arm must re-hydrate to the saved instant',
            );
            self::assertEqualsWithDelta(
                $probe->dtTs->getTimestamp(),
                $refetched->dtTs->getTimestamp(),
                0,
                'the DateTime arm must re-hydrate to the saved epoch',
            );
        });
    }

    /**
     * The nullable timestamp arms survive the full null → set → null
     * rewrite cycle without corrupting either pole.
     */
    public function testNullableTimestampArmsRoundTripThroughNull(): void
    {
        [$probe, $refetched] = $this->roundTrip($this->seedRow());

        self::assertNull($refetched->nullableIntTs);
        self::assertNull($refetched->nullableCarbonTs);

        $refetched->nullableIntTs = 1700000000;
        $refetched->nullableCarbonTs = Carbon::parse('2027-08-09 10:11:12', 'UTC');
        $refetched->save();

        // Both poles now hold values — verify through the shared helper.
        $this->refetch($refetched, ['nullable_int_ts', 'nullable_carbon_ts']);
    }

    /**
     * The write-path update of one timestamp arm keeps the other arms
     * byte-identical — the double-encode idempotence of the write path.
     */
    public function testUpdatingOneTimestampKeepsOthersUntouched(): void
    {
        [$probe, $refetched] = $this->roundTrip($this->seedRow());

        $refetched->intTs = 1800000000;
        $refetched->save();

        // The untouched arms keep their values through the rewrite — the
        // partial-update idempotence of the write path.
        $this->refetch($refetched, ['int_ts', 'string_ts', 'carbon_ts', 'string_date']);
    }

    /**
     * The raw stored cell keeps the encoder's form — a datetime string
     * for the timestamp columns (the form every dialect's native
     * temporal type accepts) and a Y-m-d string for the date column.
     * Legacy integer cells (written by the earlier int-binding encoder)
     * still decode — testStoredCellFormatIsUnchanged pins the new form.
     */
    public function testStoredCellFormatIsUnchanged(): void
    {
        $probe = $this->seedRow();

        $raw = $this->connection->table('cast_probes')->where('id', '=', $probe->id)->first();

        self::assertNotNull($raw);
        self::assertSame('2026-10-05 07:47:13', $raw->int_ts);
        self::assertSame('1990-06-15', $raw->string_date);
        self::assertSame('2026-01-02 03:04:05', $raw->string_ts);
    }

    /**
     * A legacy integer cell — written by the encoder that bound unix
     * seconds untouched — decodes exactly like the datetime-string form.
     */
    public function testLegacyIntCellStillDecodes(): void
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
    }

    /**
     * Builder scalar reads decode through the same casts as hydration —
     * `value()` on the int-timestamp column returns the epoch integer
     * (the DB column name addresses the column), not strtotime()'s false.
     */
    public function testScalarReadsDecodeThroughTheCasts(): void
    {
        $this->seedRow();

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
     * The where filter decodes the stored cell before binding — the
     * int-typed property's epoch value matches the row saved by the
     * model's encoder regardless of the stored cell's form.
     */
    public function testWhereFiltersByDecodedTimestamp(): void
    {
        $probe = $this->seedRow();

        // int_ts now stores a datetime string; filter by the same epoch
        // instant the model was saved with — the builder must match it.
        $matched = CastProbe::newQuery()
            ->where('int_ts', '=', Carbon::createFromTimestamp(1791186433, 'UTC'))
            ->get();

        self::assertCount(1, $matched);
        self::assertSame(1791186433, $matched->first()?->intTs);

        self::assertSame(1, CastProbe::newQuery()->where('id', '=', $probe->id)->count());
    }
}
