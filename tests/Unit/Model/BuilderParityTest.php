<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Tests\Support\ArrayRowConnection;
use BlueprintAU\Radiant\Tests\Support\ArrayRowConnector;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BpUser;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CollPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CollUser;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfUser;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiChild;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

/**
 * The ModelQueryBuilder parity contract: every builder helper behaves
 * like its model-level sibling — column validation fails fast on typos,
 * scalar reads decode through the casts, writes encode through them.
 */
final class BuilderParityTest extends DatabaseTestCase
{
    /**
     * Create the bp_users fixture table from the model's attributes and
     * seed two rows.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(BpUser::class, OfUser::class, OfPost::class, CollUser::class, CollPost::class, MtiUser::class, MtiChild::class);

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

    // ---- Column validation on the previously-unvalidated helpers ----

    /**
     * whereColumn() must fail fast on an unknown column.
     */
    public function testWhereColumnRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->whereColumn('name', '=', 'not_a_column');
        });
    }

    /**
     * A valid whereColumn() still runs end to end.
     */
    public function testWhereColumnAcceptsDeclaredColumns(): void
    {
        $rows = BpUser::newQuery()->whereColumn('name', '=', 'name')->orderBy('id')->get();

        self::assertCount(2, $rows);
    }

    /**
     * Nested where groups validate columns — the group must be built on
     * the MODEL builder, not a plain query builder.
     */
    public function testWhereNestedRejectsUnknownColumnInCallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [typo_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->whereNested(fn ($nested) => $nested->where('typo_column', '=', 1));
        });
    }

    /**
     * The empty-group guard still fires through the model-aware override.
     */
    public function testWhereNestedRejectsEmptyGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must contain at least one clause');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->whereNested(fn ($nested) => $nested);
        });
    }

    /**
     * on()/orOn() validate both columns against the model + its joins.
     */
    public function testOnRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')
                ->on('bp_users.name', '=', 'not_a_column');
        });
    }

    /**
     * orOn() validates both columns the same way — the OR-connector twin.
     */
    public function testOrOnRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')
                ->orOn('bp_users.name', '=', 'not_a_column');
        });
    }

    /**
     * A valid orOn() renders an OR-connected ON condition end to end.
     */
    public function testOrOnAcceptsDeclaredColumns(): void
    {
        $rows = BpUser::newQuery()
            ->join('bp_users as other', 'bp_users.id', '=', 'other.id')
            ->on('bp_users.id', '=', 'other.id')
            ->orOn('bp_users.name', '=', 'other.name')
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows);
    }

    /**
     * The join-time shield qualifies every bare spec IN PLACE — a raw
     * star becomes `table.*`, bare column names prefix with the model's
     * table, and a mixed shape keeps ALL its specs (the aggregate renders
     * verbatim beside the qualified star). No shape's columns are dropped.
     * Caller-owned qualified lists pass through untouched — a duplicate
     * bare column there is a loud DB ambiguity error, not silent
     * corruption.
     */
    public function testJoinShieldsEveryRawStarShape(): void
    {
        $sqlFor = static function (ModelQueryBuilder $builder): string {
            return (new \BlueprintAU\Radiant\Database\Grammars\SqliteGrammar())
                ->compileSelect($builder);
        };

        self::assertSame(
            'SELECT "bp_users".* FROM "bp_users" INNER JOIN "bp_users" AS "other"'
            . ' ON "bp_users"."id" = "other"."id"',
            $sqlFor(BpUser::newQuery()->join('bp_users as other', 'bp_users.id', '=', 'other.id')),
            'the untouched default select shields',
        );

        // Caller-owned specs: the aggregate renders verbatim; the bare
        // sibling column still qualifies. Nothing is dropped.
        self::assertSame(
            'SELECT count(*) AS "total", "bp_users"."name" FROM "bp_users"'
            . ' INNER JOIN "bp_users" AS "other" ON "bp_users"."id" = "other"."id"',
            $sqlFor(BpUser::newQuery()->select(Aggregate::count('*', 'total'), 'name')
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')),
            'a mixed aggregate shape keeps its aggregate and qualifies the rest',
        );

        // A hand-built raw star beside other specs: each star qualifies IN
        // PLACE — the aggregate and the bare column both survive.
        self::assertSame(
            'SELECT count(*) AS "total", "bp_users".*, "bp_users"."name" FROM "bp_users"'
            . ' INNER JOIN "bp_users" AS "other" ON "bp_users"."id" = "other"."id"',
            $sqlFor(BpUser::newQuery()->select(Aggregate::count('*', 'total'), '*', 'name')
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')),
            'a raw star beside other specs shields in place, not by collapse',
        );

        // select('*') expands to the model's columns (PK first) — the
        // join-aware qualification then lands each column QUALIFIED.
        self::assertSame(
            'SELECT "bp_users"."id", "bp_users"."name", "bp_users"."signed_up_at", "bp_users"."meta"'
            . ' FROM "bp_users" INNER JOIN "bp_users" AS "other"'
            . ' ON "bp_users"."id" = "other"."id"',
            $sqlFor(BpUser::newQuery()->select('*')
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')),
            'select(*) compiles its qualified expansion, not a raw star',
        );

        // A fully qualified list is caller-owned — no shield applies.
        self::assertSame(
            'SELECT "bp_users"."id" FROM "bp_users" INNER JOIN "bp_users" AS "other"'
            . ' ON "bp_users"."id" = "other"."id"',
            $sqlFor(BpUser::newQuery()->select('bp_users.id')
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')),
            'a qualified caller-owned select is untouched',
        );
    }

    /**
     * Bare select specs QUALIFY under a join, in both orderings.
     *
     * select('*') BEFORE the join compiles the same qualified list as a
     * late select('*') AFTER the join — the expansion and the late bare
     * names both ride the join-aware qualification, so neither ordering
     * can compile ambiguous-column SQL. Before the qualification pass, a
     * select('name') AFTER the join emitted bare `"name"` — ambiguous
     * with the joined table's own column, failing at the driver.
     */
    public function testLateBareSelectsQualifyUnderJoin(): void
    {
        $sqlFor = static function (ModelQueryBuilder $builder): string {
            return (new \BlueprintAU\Radiant\Database\Grammars\SqliteGrammar())
                ->compileSelect($builder);
        };

        $join = static fn (ModelQueryBuilder $b): ModelQueryBuilder
            => $b->join('bp_users as other', 'bp_users.id', '=', 'other.id');

        // Late narrow select qualifies — the forced PK rides the merge
        // (hydration always keeps the identity column).
        self::assertSame(
            'SELECT "bp_users"."id", "bp_users"."name" FROM "bp_users"'
            . ' INNER JOIN "bp_users" AS "other" ON "bp_users"."id" = "other"."id"',
            $sqlFor($join(BpUser::newQuery())->select('name')),
            'a late bare name qualifies to the model table',
        );

        // select('*') AFTER the join: expansion (forced-keys first) then
        // qualification — same per-column form as select-before-join.
        self::assertSame(
            'SELECT "bp_users"."id", "bp_users"."name", "bp_users"."signed_up_at", "bp_users"."meta"'
            . ' FROM "bp_users" INNER JOIN "bp_users" AS "other"'
            . ' ON "bp_users"."id" = "other"."id"',
            $sqlFor($join(BpUser::newQuery())->select('*')),
            'a late select(*) expands AND qualifies',
        );

        // Both orderings of select('*') land on the same SQL.
        self::assertSame(
            $sqlFor(BpUser::newQuery()->select('*')->join('bp_users as other', 'bp_users.id', '=', 'other.id')),
            $sqlFor($join(BpUser::newQuery())->select('*')),
            'select ordering is irrelevant',
        );

        // ...and executes: hydration reads THIS table's values.
        $rows = $join(BpUser::newQuery())->select('*')->orderBy('bp_users.id')->get();
        self::assertCount(2, $rows);
        self::assertSame('ada', $rows->first()?->attribute('name'));
    }

    /**
     * Hydration stays correct when a JOINED table carries duplicate
     * column names — the shield keeps the joined table's cells out of the
     * row, so the hydrated model reads THIS table's values.
     */
    public function testJoinedHydrationKeepsModelValues(): void
    {
        $rows = BpUser::newQuery()
            ->join('bp_users as other', 'bp_users.id', '=', 'other.id')
            ->orderBy('bp_users.id')
            ->get();

        self::assertCount(2, $rows);
        self::assertSame('ada', $rows->first()?->attribute('name'));
        self::assertSame('ben', $rows->last()?->attribute('name'));
    }

    // ---- Scalar reads decode through the casts ----

    /**
     * value() on a declared datetime column decodes to Carbon.
     */
    public function testValueDecodesDatetime(): void
    {
        $value = BpUser::newQuery()->where('name', '=', 'ada')->value('signed_up_at');

        self::assertInstanceOf(\Carbon\Carbon::class, $value);
        self::assertSame('2026-01-15 10:00:00', $value->format('Y-m-d H:i:s'));
    }

    /**
     * value() on an aggregate passes through the computed value.
     */
    public function testValuePassesThroughUnknownExpressions(): void
    {
        $value = BpUser::newQuery()->value(Aggregate::count());

        self::assertSame(2, (int) $value);
    }

    /**
     * pluck() decodes declared columns — datetimes come back as Carbons.
     */
    public function testPluckDecodesDatetime(): void
    {
        $values = BpUser::newQuery()->whereNotNull('signed_up_at')->orderBy('id')->pluck('signed_up_at');

        self::assertCount(1, $values);
        self::assertInstanceOf(\Carbon\Carbon::class, $values[0]);
    }

    /**
     * max() on a datetime column decodes to Carbon, not a raw string.
     */
    public function testMaxDecodesDatetime(): void
    {
        $max = BpUser::newQuery()->max('signed_up_at');

        self::assertInstanceOf(\Carbon\Carbon::class, $max);
        self::assertSame('2026-01-15 10:00:00', $max->format('Y-m-d H:i:s'));
    }

    /**
     * aggregates() decodes each aggregate's column cast.
     */
    public function testAggregatesDecodeColumns(): void
    {
        $result = BpUser::newQuery()->aggregates(
            Aggregate::count('*', 'total'),
            Aggregate::max('signed_up_at', 'latest'),
        );

        self::assertSame(2, (int) $result->total);
        self::assertInstanceOf(\Carbon\Carbon::class, $result->latest);
    }

    // ---- Grouped aggregates decode through the casts ----

    /**
     * countBy() groups the rows per column with int counts.
     */
    public function testCountByGroupsRows(): void
    {
        $counts = BpUser::newQuery()->countBy('name');

        self::assertSame(1, $counts['ada']);
        self::assertSame(1, $counts['ben']);
        self::assertCount(2, $counts);
    }

    /**
     * The countBy() seed is additive: absent seeded groups become 0 and
     * unlisted database values still appear.
     */
    public function testCountBySeedIsAdditive(): void
    {
        $counts = BpUser::newQuery()->countBy('name', ['ada', 'ben', 'carol']);

        self::assertSame(1, $counts['ada']);
        self::assertSame(1, $counts['ben']);
        self::assertSame(0, $counts['carol']);
        self::assertCount(3, $counts);
    }

    /**
     * aggregateBy() decodes a declared column's cast — a datetime column
     * yields Carbon per group.
     */
    public function testAggregateByDecodesColumns(): void
    {
        $maxes = BpUser::newQuery()->aggregateBy(Aggregate::max('signed_up_at'), 'name');

        self::assertInstanceOf(\Carbon\Carbon::class, $maxes['ada']);
        self::assertSame('2026-01-15 10:00:00', $maxes['ada']->format('Y-m-d H:i:s'));
        self::assertNull($maxes['ben'], 'max over an all-NULL group is SQL NULL');
    }

    /**
     * countBy() composes with the model-aware filters — the where
     * constrains the grouped query too.
     */
    public function testCountByRespectsComposedFilters(): void
    {
        $counts = BpUser::newQuery()->where('name', '=', 'ada')->countBy('name');

        self::assertSame(['ada' => 1], $counts->all());
    }

    // ---- Writes encode through the casts ----

    /**
     * Builder-level insert encodes DateTime/array values like save() does.
     */
    public function testInsertEncodesThroughCasts(): void
    {
        BpUser::newQuery()->insert([
            'name' => 'grace',
            'signed_up_at' => \Carbon\Carbon::parse('2026-03-01 08:30:00'),
            'meta' => ['lang' => 'en'],
        ]);

        $raw = $this->connection->table('bp_users')->where('name', '=', 'grace')->first();

        self::assertNotNull($raw);
        self::assertSame('2026-03-01 08:30:00', $raw->signed_up_at);
        self::assertNotNull($raw->meta);

        $decoded = json_decode((string) $raw->meta, true);
        self::assertSame('en', $decoded['lang']);
    }

    /**
     * Builder-level update encodes DateTime values through the cast.
     */
    public function testUpdateEncodesThroughCasts(): void
    {
        $affected = BpUser::newQuery()->where('name', '=', 'ben')
            ->update(['signed_up_at' => \Carbon\Carbon::parse('2026-02-02 09:00:00')]);

        self::assertSame(1, $affected);

        $fresh = BpUser::newQuery()->where('name', '=', 'ben')->first();
        self::assertNotNull($fresh);
        self::assertInstanceOf(\Carbon\Carbon::class, $fresh->signedUpAt);
        self::assertSame('2026-02-02 09:00:00', $fresh->signedUpAt->format('Y-m-d H:i:s'));
    }

    /**
     * Builder-level writes reject unknown columns — the write path
     * validates as hard as the read path.
     */
    public function testInsertRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->insert(['name' => 'x', 'not_a_column' => 1]);
        });
    }

    /**
     * Bulk insert encodes every row and validates every key.
     */
    public function testBulkInsertEncodesThroughCasts(): void
    {
        BpUser::newQuery()->insert([
            ['name' => 'h1', 'signed_up_at' => \Carbon\Carbon::parse('2026-04-01 00:00:00'), 'meta' => ['a' => 1]],
            ['name' => 'h2', 'signed_up_at' => null, 'meta' => ['b' => 2]],
        ]);

        $rows = BpUser::newQuery()->whereIn('name', ['h1', 'h2'])->orderBy('name')->get();

        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertInstanceOf(\Carbon\Carbon::class, $rows[0]->signedUpAt);
        self::assertSame(['b' => 2], $rows[1]->meta);
    }

    /**
     * Bulk insert validates EVERY row's keys — a bad key in the second
     * row rejects even though the first row is clean.
     */
    public function testBulkInsertValidatesEveryRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->insert([
                ['name' => 'clean'],
                ['name' => 'dirty', 'not_a_column' => 1],
            ]);
        });
    }

    /**
     * Bulk insert encodes DateTime values in EVERY row — not just the
     * first (the per-row encode loop, not a one-shot cast).
     */
    public function testBulkInsertEncodesEveryRow(): void
    {
        BpUser::newQuery()->insert([
            ['name' => 'd1', 'signed_up_at' => \Carbon\Carbon::parse('2026-05-01 01:00:00')],
            ['name' => 'd2', 'signed_up_at' => \Carbon\Carbon::parse('2026-05-02 02:00:00')],
        ]);

        $rows = BpUser::newQuery()->whereIn('name', ['d1', 'd2'])->orderBy('name')->get();

        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertNotNull($rows[0]->signedUpAt);
        self::assertNotNull($rows[1]->signedUpAt);
        self::assertSame('2026-05-01 01:00:00', $rows[0]->signedUpAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-02 02:00:00', $rows[1]->signedUpAt->format('Y-m-d H:i:s'));
    }

    /**
     * insertGetId() validates and encodes like insert() — an unknown
     * column rejects before any SQL runs.
     */
    public function testInsertGetIdRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->insertGetId(['name' => 'x', 'not_a_column' => 1]);
        });
    }

    /**
     * insertGetId() encodes through the casts and returns the generated
     * id.
     */
    public function testInsertGetIdEncodesThroughCasts(): void
    {
        $id = BpUser::newQuery()->insertGetId([
            'name' => 'gid',
            'signed_up_at' => \Carbon\Carbon::parse('2026-06-01 03:00:00'),
            'meta' => ['k' => 'v'],
        ]);

        self::assertNotNull($id);

        $fresh = BpUser::newQuery()->where('name', '=', 'gid')->first();
        self::assertNotNull($fresh);
        self::assertNotNull($fresh->signedUpAt);
        self::assertSame('2026-06-01 03:00:00', $fresh->signedUpAt->format('Y-m-d H:i:s'));
        self::assertSame(['k' => 'v'], $fresh->meta);
    }

    /**
     * A list of SCALARS is caller error — never a valid row set. It must
     * fail fast, not warn-and-skip: the old duplicated foreach loop only
     * emitted PHP warnings and silently encoded EMPTY rows (a row with
     * just the column defaults landed in the table, insert() reported 1).
     * Delegating each entry to encodeRow()'s native `array` parameter
     * turns the mistake into a TypeError.
     */
    public function testBulkInsertRejectsScalarRows(): void
    {
        $this->expectException(\TypeError::class);

        $this->runInvalid(function (): void {
            BpUser::newQuery()->insert(['name', 'age']);
        });
    }

    // ---- Streaming hydrates ----

    /**
     * cursor() yields hydrated models — the streaming counterpart of get().
     */
    public function testCursorYieldsHydratedModels(): void
    {
        $models = [];

        foreach (BpUser::newQuery()->orderBy('id')->cursor() as $model) {
            $models[] = $model;
        }

        self::assertCount(2, $models);
        // PHPStan 2.2.16 flags this as already-narrowed (the cursor() PHPDoc
        // promises list<BpUser>), but the assertion is the test's subject —
        // it guards the runtime type, not the annotation.
        /** @phpstan-ignore staticMethod.alreadyNarrowedType (the assertion guards the runtime type, not the PHPDoc) */
        self::assertContainsOnlyInstancesOf(BpUser::class, $models);
        self::assertSame('ada', $models[0]->name);
        self::assertInstanceOf(\Carbon\Carbon::class, $models[0]->signedUpAt);
    }

    // ---- The JSON round-trip guard ----

    /**
     * A decoded JSON array written back through update() must round-trip
     * — NOT double-encode into a quoted JSON string.
     */
    public function testJsonRoundTripDoesNotDoubleEncode(): void
    {
        $user = BpUser::newQuery()->where('name', '=', 'ada')->first();
        self::assertNotNull($user);
        self::assertSame('dark', $user->meta['theme'] ?? null);

        // Write the DECODED array back via the builder — the encode must
        // produce the same cell content, not `"{\"theme\":\"dark\"}"`.
        BpUser::newQuery()->where('name', '=', 'ada')->update(['meta' => ['theme' => 'light']]);

        $fresh = BpUser::newQuery()->where('name', '=', 'ada')->first();
        self::assertNotNull($fresh);
        self::assertSame('light', $fresh->meta['theme'] ?? null);
    }

    // ---- Helpers ----

    /**
     * Run a callable expected to throw — the mixed-typed boundary so
     * PHPStan cannot flag the invalid argument at the call site.
     *
     * @param callable(): void $callback The invalid invocation.
     * @return void
     */
    private function runInvalid(callable $callback): void
    {
        $callback();
    }

    /**
     * A mixed-typed value — the boundary for the PHPDoc-only KeyValue
     * contract.
     *
     * @return mixed
     */
    private function mixedValue(): mixed
    {
        return new \stdClass();
    }

    // ---- Hydration guards ----

    /**
     * sole() fails fast when the connection returns a NON-stdClass row —
     * the malformed-shape guard before hydration.
     */
    public function testSoleRejectsNonStdClassRow(): void
    {
        ArrayRowConnector::install($this->manager);
        $this->manager->flush('default'); // evict the cached sqlite connection
        ArrayRowConnection::$rows = [['id' => 1, 'name' => 'ada']];

        $this->expectException(ModelNotFoundException::class);

        // The setUp seed put 2 rows in the sqlite table, but the swapped
        // connection returns exactly ONE canned (array) row — the count
        // passes, the shape guard fires.
        BpUser::newQuery()->sole();
    }

    // ---- clearRelationCache ----

    /**
     * clearRelationCache(null) empties the whole static cache — a
     * subsequent resolveRelation() repopulates it from scratch.
     */
    public function testClearRelationCacheNullClearsEverything(): void
    {
        // Populate the cache for two classes.
        OfUser::newQuery()->with(['posts']);
        CollUser::newQuery()->with(['posts']);

        ModelQueryBuilder::clearRelationCache();

        // Repopulate one class and verify the cache serves it again.
        OfUser::newQuery()->with(['posts']);
        $rows = OfUser::newQuery()->with(['posts'])->get();
        self::assertCount(0, $rows);
    }

    /**
     * clearRelationCache(Class) removes only that class's entries —
     * other classes' cached relations survive.
     */
    public function testClearRelationCacheClassClearsOnlyThatClass(): void
    {
        // Populate the cache for two classes.
        OfUser::newQuery()->with(['posts']);
        CollUser::newQuery()->with(['posts']);

        ModelQueryBuilder::clearRelationCache(OfUser::class);

        // The cleared class re-resolves fine; the untouched class's cache
        // entry is still present (no error, no re-resolution needed).
        OfUser::newQuery()->with(['posts']);
        CollUser::newQuery()->with(['posts']);
        self::assertCount(0, OfUser::newQuery()->with(['posts'])->get());
    }

    // ---- sole() ----

    /**
     * sole() throws ModelNotFoundException on zero rows — the empty arm.
     */
    public function testSoleThrowsOnEmptyResult(): void
    {
        $this->expectException(ModelNotFoundException::class);

        OfUser::newQuery()->where('name', '=', 'nobody')->sole();
    }

    // ---- whereKey on a composite PK ----

    /**
     * whereKey() with a scalar on a composite-PK model throws — the
     * composite PK needs an array of column => value.
     */
    public function testWhereKeyScalarOnCompositePkThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('has a composite PK; pass an array of column => value');

        $this->runInvalid(function (): void {
            CmpRegion::newQuery()->whereKey(5);
        });
    }

    /**
     * whereKey() with a list containing a scalar on a composite-PK model
     * throws — the list path validates each element against the PK shape.
     */
    public function testWhereKeyListScalarOnCompositePkThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('has a composite PK; pass an array of column => value');

        $this->runInvalid(function (): void {
            CmpRegion::newQuery()->whereKey([5, 6]);
        });
    }

    /**
     * whereKey() with a list containing a NON-scalar element throws —
     * the runtime boundary behind the PHPDoc-only KeyValue contract.
     */
    public function testWhereKeyListElementBadTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A single primary-key value must be int, string or null; got stdClass');

        $this->runInvalid(function (): void {
            $bad = $this->mixedValue();
            BpUser::newQuery()->whereKey([1, $bad]);
        });
    }

    /**
     * whereKey() with a LIST on an MTI model qualifies the PK — the
     * shared key exists on every joined table.
     */
    public function testWhereKeyListOnMtiQualifiesPk(): void
    {
        $first = new MtiChild();
        $first->email = 'a@example.com';
        $first->level = 'lead';
        $first->save();

        $second = new MtiChild();
        $second->email = 'b@example.com';
        $second->level = 'senior';
        $second->save();

        $rows = MtiChild::newQuery()->whereKey([$first->id, $second->id])->orderBy('id')->get();

        self::assertCount(2, $rows);
    }

    /**
     * whereKey() with a composite MAP on an MTI model qualifies each PK
     * column — the tuple lands in one nested group.
     */
    public function testWhereKeyCompositeMapOnMtiQualifiesColumns(): void
    {
        $root = new MtiUser();
        $root->email = 'a@example.com';
        $root->save();

        $child = new MtiChild();
        $child->email = 'b@example.com';
        $child->level = 'lead';
        $child->save();

        $rows = MtiChild::newQuery()->whereKey(['id' => $child->id])->get();

        self::assertCount(1, $rows);
        $row = $rows->first();
        self::assertNotNull($row);
        self::assertSame('lead', $row->level);
    }

    // ---- Nested eager loads ----

    /**
     * sole() applies eager loads to the single model — the same tail as
     * first().
     */
    public function testSoleAppliesEagerLoads(): void
    {
        $user = new OfUser();
        $user->name = 'ada';
        $user->save();

        $post = new OfPost();
        $post->authorId = $user->id;
        $post->title = 'first';
        $post->save();

        $user = OfUser::newQuery()->where('name', '=', 'ada')->with(['posts'])->sole();

        $posts = $user->cachedRelation('posts');
        self::assertInstanceOf(\BlueprintAU\Radiant\Collection::class, $posts);
        self::assertCount(1, $posts);
    }
}
