<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\EnumWhereProbe;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OtherSource;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\WhereKind;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\WhereLevel;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\WhereSource;

/**
 * Enum cases in where values: the query-path twin of the write path's
 * enum casting. A case binds through the validated column's cast —
 * backed enum to its backing value, unit enum to its case name —
 * everywhere a where value is accepted, while raw backing values keep
 * working and a case of the wrong enum fails fast naming the column.
 */
final class EnumWhereTest extends DatabaseTestCase
{
    /**
     * Create the probe table straight from the fixture's metadata — the
     * attribute declarations ARE the schema contract under test.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(EnumWhereProbe::class);
    }

    /**
     * Two probe rows: `login`/Low/Alpha and `reset`/High/Beta — enough
     * spread to prove equality filters pick the right row and list
     * filters see both sides.
     *
     * @return list<EnumWhereProbe>
     */
    private function seedRows(): array
    {
        $login = new EnumWhereProbe();
        $login->source = WhereSource::Login;
        $login->level = WhereLevel::Low;
        $login->kind = WhereKind::Alpha;
        $login->save();

        $reset = new EnumWhereProbe();
        $reset->source = WhereSource::Reset;
        $reset->level = WhereLevel::High;
        $reset->kind = WhereKind::Beta;
        $reset->save();

        return [$login, $reset];
    }

    /**
     * A string-backed enum case binds through an Enum column and finds
     * the saved row.
     */
    public function testBackedStringCaseMatchesRow(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereEq('source', WhereSource::Login)->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereSource::Login, $first->source);
    }

    /**
     * A raw backing value keeps working — hosts legitimately pass raw
     * values decoded from request bodies, and mixed call sites (some
     * passing cases, some raw) must both find the same row.
     */
    public function testRawBackingValueParity(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereEq('source', 'login')->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereSource::Login, $first->source);
    }

    /**
     * An int-backed enum case binds through the cast and matches the
     * stored int cell — the encoded int is what PDO receives, so the
     * PARAM_INT fall-out rides the encoded value.
     */
    public function testBackedIntCaseMatchesRow(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereEq('level', WhereLevel::High)->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereLevel::High, $first->level);
    }

    /**
     * A unit enum case binds by case name (encodeEnum's convention) and
     * matches the stored name cell.
     */
    public function testUnitCaseMatchesByCaseName(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereEq('kind', WhereKind::Alpha)->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereKind::Alpha, $first->kind);
    }

    /**
     * A case inside a whereIn() list binds element-wise and finds both
     * rows.
     */
    public function testCaseInWhereInList(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereIn('source', [WhereSource::Login, WhereSource::Reset])->get();

        self::assertCount(2, $found);
    }

    /**
     * A MIXED whereIn() list — one enum case plus one raw backing value
     * — keeps working: case callers and raw-value call sites can share
     * one clause.
     */
    public function testMixedCaseAndRawList(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereIn('level', [WhereLevel::Low, 5])->get();

        self::assertCount(2, $found);
    }

    /**
     * whereNotIn() with cases excludes the listed rows — the negative
     * operators encode their lists identically.
     */
    public function testCaseInWhereNotInExcludesRows(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereNotIn('source', [WhereSource::Reset])->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereSource::Login, $first->source);
    }

    /**
     * Enum cases work as a whereBetween() range — the between arm
     * encodes element-wise too.
     */
    public function testCasesInWhereBetween(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereBetween('level', [WhereLevel::Low, WhereLevel::High])->get();

        self::assertCount(2, $found);
    }

    /**
     * A case filters through a nested where group too — nested builders
     * delegate to the owning builder's where(), so the encoding applies
     * without a separate override.
     */
    public function testCaseInsideNestedGroup(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()
            ->whereNested(
                fn (\BlueprintAU\Radiant\Database\Query\WhereBuilder $nested) => $nested->whereEq('kind', WhereKind::Alpha),
            )
            ->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereKind::Alpha, $first->kind);
    }

    /**
     * The static forwarder (Model::whereEq → FiltersStaticQuery) lands
     * on the same ModelQueryBuilder where() and binds the case the same
     * way.
     */
    public function testStaticForwarderAcceptsCase(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::whereEq('source', WhereSource::Reset)->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereSource::Reset, $first->source);
    }

    /**
     * A case of a DIFFERENT enum against the column fails fast at
     * declaration, naming the column — full parity with the write path's
     * wrong-enum guard.
     */
    public function testWrongEnumCaseFailsFast(): void
    {
        $thrown = Expectation::throws(
            fn () => EnumWhereProbe::newQuery()->whereEq('source', OtherSource::Login),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('source', $thrown->getMessage());
    }

    /**
     * The declaration-level list guard still fires before encoding: a
     * nested array with a comparison operator is a caller error, not an
     * encoding concern.
     */
    public function testListWithComparisonOperatorStillThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => EnumWhereProbe::newQuery()->whereEq('source', ['nested']),
            \InvalidArgumentException::class,
            'list values belong to',
        );
    }

    /**
     * An INVALID raw value against an enum column fails fast naming the
     * column — full write-path parity. A VALID backing value ('login')
     * keeps passing through (testRawBackingValueParity): request-body
     * call sites bind raw values legitimately, and the cast's tryFrom
     * accepts exactly those.
     */
    public function testRawValueMatchingNoCaseFailsFast(): void
    {
        $thrown = Expectation::throws(
            fn () => EnumWhereProbe::newQuery()->whereEq('source', 'bogus')->get(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('source', $thrown->getMessage());
    }

    /**
     * Strictness for genuinely unbindable where values is preserved: an
     * stdClass still reaches the binding layer's guard (encoding never
     * touches non-enum values). The guard's InvalidArgumentException
     * propagates unwrapped — only PDOException becomes a QueryException.
     */
    public function testUnbindableObjectStillRejected(): void
    {
        Expectation::throwsWithMessage(
            fn () => Database::table('where_probes')->whereEq('source', new \stdClass())->get(),
            \InvalidArgumentException::class,
            'Binding must be a scalar',
        );
    }

    /**
     * An enum case against a plain (non-model) QueryBuilder column has
     * no model cast to consult — the unencoded object reaches the SQL
     * binding guard, which rejects it. The encode-on-where contract is a
     * MODEL builder feature by design.
     */
    public function testCaseOnPlainBuilderColumnRejected(): void
    {
        Expectation::throwsWithMessage(
            fn () => Database::table('where_probes')->whereEq('kind', WhereKind::Alpha)->get(),
            \InvalidArgumentException::class,
            'Binding must be a scalar',
        );
    }

    /**
     * A Carbon value on a millisecond-precision datetime column binds
     * the stored form exactly — a second-precision codec bind would
     * MISS the row (millisecond cells vs `'H:i:s'` strings compare
     * unequal in SQL).
     */
    public function testPrecisionDatetimeMatchesStoredForm(): void
    {
        $probe = new EnumWhereProbe();
        $probe->source = WhereSource::Login;
        $probe->level = WhereLevel::Low;
        $probe->kind = WhereKind::Alpha;
        $probe->loggedAt = \Carbon\Carbon::parse('2026-01-02 03:04:05.678', 'UTC');
        $probe->save();

        $found = EnumWhereProbe::newQuery()->whereEq('logged_at', \Carbon\Carbon::parse('2026-01-02 03:04:05.678', 'UTC'))->get();

        self::assertCount(1, $found);
    }

    /**
     * A DateTime value on a Date column binds the `Y-m-d` cell form —
     * the codec's full datetime string would never match a date cell.
     */
    public function testDateColumnBindsCellForm(): void
    {
        $probe = new EnumWhereProbe();
        $probe->source = WhereSource::Login;
        $probe->level = WhereLevel::Low;
        $probe->kind = WhereKind::Alpha;
        $probe->startDay = \Carbon\Carbon::parse('2026-06-15', 'UTC');
        $probe->save();

        $found = EnumWhereProbe::newQuery()->whereEq('start_day', \Carbon\Carbon::parse('2026-06-15 14:30:00', 'UTC'))->get();

        self::assertCount(1, $found);
    }

    /**
     * An array under a comparison operator keeps the accurate
     * declaration error — arrays pass through encoding untouched so the
     * parent's list guard fires. (Json EQUALITY stays out of scope by
     * design: equality on json cells is dialect-fragmented — Postgres'
     * `json` type has no equality operator at all.)
     */
    public function testArrayUnderComparisonOperatorThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => EnumWhereProbe::newQuery()->whereEq('meta', ['theme' => 'dark'])->get(),
            \InvalidArgumentException::class,
            'list values belong to',
        );
    }

    /**
     * A LIKE pattern bypasses encoding — a pattern is a match template,
     * not a cell value; casting it would fail fast on enum/uuid columns
     * (`'%og%'` is not a case backing value).
     */
    public function testLikePatternBypassesEncoding(): void
    {
        $this->seedRows();

        $found = EnumWhereProbe::newQuery()->whereLike('source', '%og%')->get();

        self::assertCount(1, $found);
        $first = $found->first();
        self::assertNotNull($first);
        self::assertSame(WhereSource::Login, $first->source);
    }

    /**
     * Having() encodes through the compared column's cast — an enum
     * case filters a grouped enum column without a manual ->value.
     */
    public function testHavingEncodesEnumCase(): void
    {
        $this->seedRows();

        $rows = EnumWhereProbe::newQuery()
            ->select('kind')
            ->groupBy('kind')
            ->having('kind', '=', WhereKind::Alpha)
            ->aggregates(
                \BlueprintAU\Radiant\Database\Query\Aggregate::count('*', 'total'),
            );

        self::assertSame(1, (int) $rows->total);
    }

    /**
     * Having() over a typed aggregate encodes by the aggregate's INNER
     * column — `max('level')` compares a level-cell value.
     */
    public function testHavingOverAggregateEncodesInnerColumn(): void
    {
        $this->seedRows();

        // HAVING over a typed aggregate filters GROUPS: with no group-by,
        // the whole table is one group — the aggregate row exists only
        // when `max(level) = 5` holds (a mis-encoded value would leave no
        // group and aggregates() would throw).
        $rows = EnumWhereProbe::newQuery()
            ->having(\BlueprintAU\Radiant\Database\Query\Aggregate::max('level'), '=', WhereLevel::High)
            ->aggregates(\BlueprintAU\Radiant\Database\Query\Aggregate::max('level', 'top'));

        self::assertSame(WhereLevel::High, $rows->top);
    }

    /**
     * An invalid raw value in having fails fast the same way — the
     * same helper guards both clause families.
     */
    public function testHavingInvalidRawValueFailsFast(): void
    {
        $thrown = Expectation::throws(
            fn () => EnumWhereProbe::newQuery()->having('kind', '=', 'bogus'),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('kind', $thrown->getMessage());
    }
}
