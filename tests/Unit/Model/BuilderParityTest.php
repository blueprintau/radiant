<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BpUser;

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
        $this->createTables(BpUser::class);

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
}
