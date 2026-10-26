<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\SubquerySelect;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\ModelIntrospection;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfUser;

/**
 * Retention of undeclared select columns through hydration.
 *
 * A select list may carry values the model does not declare — a
 * correlated scalar subquery (the withCount-style one-query feed), a
 * computed expression, any caller-chosen alias. `fromRow()` retains
 * every undeclared, non-reserved row field on a read-only bag read
 * through `attribute()`; these tests pin the retention contract end to
 * end on a live SQLite database.
 */
final class ExtraColumnsHydrationTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(OfUser::class, OfPost::class);
    }

    /**
     * Seed two users and three posts (three on Alice, none on Bob) so a
     * correlated count subquery has a real spread to read.
     *
     * @return array{a: OfUser, b: OfUser}
     */
    private function seed(): array
    {
        $a = new OfUser();
        $a->name = 'Alice';
        $a->save();

        $b = new OfUser();
        $b->name = 'Bob';
        $b->save();

        foreach (['P1', 'P2', 'P3'] as $title) {
            $post = new OfPost();
            $post->authorId = $a->id;
            $post->title = $title;
            $post->save();
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * The post-count subquery correlated to the users table — built on a
     * BASE builder (a model builder's allowlist refuses the outer-table
     * reference), the one-query-feed shape the feature exists for.
     *
     * @return SubquerySelect
     */
    private function commentCountSelect(): SubquerySelect
    {
        return new SubquerySelect(
            $this->connection->table('of_posts')
                ->select(Aggregate::count())
                ->whereColumn('of_posts.author_id', '=', 'of_users.id'),
            'post_count',
        );
    }

    /**
     * The original repro: a correlated subquery alias hydrates readable.
     */
    public function testSubqueryAliasRetainedThroughHydration(): void
    {
        $this->seed();

        $rows = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->orderBy('id')
            ->get();

        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertSame(3, $rows[0]->attribute('post_count'));
        self::assertSame(0, $rows[1]->attribute('post_count'));
    }

    /**
     * Retention covers every hydration funnel — first(), sole(), and
     * cursor() all land in fromRow().
     */
    public function testRetentionThroughFirstSoleAndCursor(): void
    {
        $this->seed();

        $first = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->where('id', '=', 1)
            ->first();

        self::assertNotNull($first);
        self::assertSame(3, $first->attribute('post_count'));

        $sole = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->where('id', '=', 1)
            ->sole();

        self::assertSame(3, $sole->attribute('post_count'));

        $seen = [];

        foreach (OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->orderBy('id')
            ->cursor() as $user) {
            $seen[$user->attribute('id')] = $user->attribute('post_count');
        }

        self::assertSame([1 => 3, 2 => 0], $seen);
    }

    /**
     * The bag is consulted only for names the model does not map — a
     * declared column keeps its normal read path and is never shadowed.
     */
    public function testDeclaredColumnWinsOverBag(): void
    {
        $this->seed();

        $model = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->where('id', '=', 1)
            ->first();

        self::assertNotNull($model);
        self::assertSame('Alice', $model->attribute('name'));
        self::assertSame(3, $model->attribute('post_count'));
    }

    /**
     * A key that is neither declared nor present in the row still throws
     * the canonical unknown-column failure.
     */
    public function testUnknownColumnStillThrows(): void
    {
        $this->seed();

        $model = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->where('id', '=', 1)
            ->first();

        self::assertNotNull($model);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [ghost] on model [of_users].');

        $model->attribute('ghost');
    }

    /**
     * Undeclared values stay OUT of the write path: a hydrated model
     * carrying extras reports no dirty columns and save() runs no UPDATE.
     */
    public function testExtraColumnsAreNotSpuriouslyDirty(): void
    {
        $this->seed();

        $model = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->where('id', '=', 1)
            ->first();

        self::assertNotNull($model);
        self::assertSame([], ModelIntrospection::dirtyOf($model));
    }

    /**
     * A row carrying ONLY declared columns retains nothing — the bag holds
     * just the undeclared extras, never a duplicate of the declared state.
     */
    public function testPlainRowLeavesTheBagEmpty(): void
    {
        $this->seed();

        $model = OfUser::newQuery()->where('id', '=', 1)->first();

        self::assertNotNull($model);

        $bag = new \ReflectionProperty(Model::class, 'extraColumnValues');

        self::assertSame([], $bag->getValue($model));
    }

    /**
     * The reserved namespace never leaks into the bag: pivot aliases
     * still lift into the pivot store, and every other `radiant_*` row
     * field is dropped outright.
     */
    public function testReservedAliasesNeverEnterTheBag(): void
    {
        $this->seed();

        $model = OfUser::newInstance();

        $row = new \stdClass();
        $row->id = 1;
        $row->name = 'Alice';
        $row->radiant_pivot_position = 'left';
        $row->radiant_scalar = 7;
        $row->computed_extra = 'kept';

        $hydrated = OfUser::fromRow($row);

        self::assertSame('left', $hydrated->pivotValue('position'));
        self::assertSame('kept', $hydrated->attribute('computed_extra'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [radiant_scalar] on model [of_users].');
        $hydrated->attribute('radiant_scalar');
    }

    /**
     * A SubquerySelect alias under the reserved prefix throws at
     * declaration — it would be lifted as pivot data or dropped at
     * hydration instead of readable.
     */
    public function testReservedAliasRejectedAtDeclaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('starts with the reserved prefix');

        new SubquerySelect(
            $this->connection->table('of_posts')->select(Aggregate::count()),
            'radiant_pivot_foo',
        );
    }

    /**
     * The static forwarder accepts a SubquerySelect exactly like the
     * instance builder.
     */
    public function testStaticSelectAcceptsSubquerySelect(): void
    {
        $this->seed();

        $rows = OfUser::select('id', 'name', $this->commentCountSelect())
            ->orderBy('id')
            ->get();

        self::assertNotNull($rows[0]);
        self::assertSame(3, $rows[0]->attribute('post_count'));
    }

    /**
     * Extra columns are query-derived, not row state: Collection::fresh()
     * re-queries the default columns, so the replaced models no longer
     * carry the bag.
     */
    public function testFreshDiscardsExtraColumns(): void
    {
        $this->seed();

        $rows = OfUser::newQuery()
            ->select('id', 'name', $this->commentCountSelect())
            ->orderBy('id')
            ->get();

        self::assertNotNull($rows[0]);
        self::assertSame(3, $rows[0]->attribute('post_count'));

        $rows->fresh();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [post_count] on model [of_users].');

        self::assertNotNull($rows[0]);
        $rows[0]->attribute('post_count');
    }

    /**
     * A select list containing a SubquerySelect is caller-owned — the
     * forced-PK re-merge is skipped, so the caller must include the PK.
     * The PK still hydrates (it IS a declared column) but the model's
     * identity path sees exactly what the caller selected.
     */
    public function testCallerOwnedSelectStillHydratesDeclaredColumns(): void
    {
        $this->seed();

        // Omitting the PK is the caller's explicit choice; the extra
        // alias still hydrates alongside the selected declared columns.
        $rows = OfUser::newQuery()
            ->select('name', $this->commentCountSelect())
            ->orderBy('id')
            ->get();

        self::assertNotNull($rows[0]);
        self::assertSame('Alice', $rows[0]->attribute('name'));
        self::assertSame(3, $rows[0]->attribute('post_count'));
    }
}
