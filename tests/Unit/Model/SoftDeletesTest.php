<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Exceptions\StaleRowException;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Support\ModelIntrospection;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiSoftChild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\SdPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\SdRenamedPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\SdUndeclaredOverridePost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

/**
 * Dedicated coverage for the {@see SoftDeletes} trait beyond the lifecycle
 * in MetadataPipelineTest: the stale-row contract (delete()/restore() are
 * idempotent when the row is gone), unsaved-model throws, re-delete
 * semantics, custom column names, forceDelete, trashed() in-memory state,
 * withTrashed/onlyTrashed, and CSV portability.
 */
final class SoftDeletesTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables from METADATA — fromMetadata() injects the
     * synthetic deleted_at for SdPost and finds SdRenamedPost's user-declared
     * renamed_at column, mirroring how a real host creates schema.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(MtiUser::class, MtiSoftChild::class, SdPost::class, SdRenamedPost::class);
    }

    /**
     * Seed one post and return it.
     *
     * @return SdPost The saved post.
     */
    private function seedPost(): SdPost
    {
        $post = new SdPost();
        $post->title = 'Hello';
        $post->save();

        return $post;
    }

    /**
     * delete() on an UNSAVED model throws — there is no row to
     * soft-delete, and a silent no-op would let the caller believe a
     * deletion happened. (Pre-void era: the call returned false.)
     */
    public function testDeleteOnUnsavedModelThrows(): void
    {
        $post = new SdPost();
        $post->title = 'Never saved';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('no row to delete');

        $post->delete();
    }

    /**
     * Same contract for restore(): an unsaved model has no row to
     * restore — LogicException, not a silent no-op.
     */
    public function testRestoreOnUnsavedModelThrows(): void
    {
        $post = new SdPost();
        $post->title = 'Never saved';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('no row to restore');

        $post->restore();
    }

    /**
     * delete() flips trashed(), hides the row from the default scope, and
     * keeps it reachable via withTrashed().
     */
    public function testDeleteLifecycle(): void
    {
        $post = $this->seedPost();

        self::assertFalse($post->trashed());
        $post->delete();
        self::assertTrue($post->trashed(), 'trashed() reads the in-memory state after delete');

        self::assertCount(0, SdPost::all(), 'the default scope excludes soft-deleted rows');

        $trashed = SdPost::newQuery()->onlyTrashed()->get();
        self::assertCount(1, $trashed);

        $all = SdPost::newQuery()->withTrashed()->get();
        self::assertCount(1, $all);
    }

    /**
     * Re-deleting an already-soft-deleted row succeeds — the UPDATE
     * matches the row directly (whereKey bypasses the scope) and refreshing
     * the timestamp is a legitimate soft delete.
     */
    public function testRedeleteSucceeds(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $post->delete();

        self::assertTrue($post->trashed(), 're-delete targets the row directly and succeeds');
    }

    /**
     * THE TRASHED-SAVE GUARD: save() on a soft-deleted model throws — its
     * UPDATE would carry the auto-applied `deleted_at IS NULL` scope, match
     * 0 rows, and report success for a write that never landed. (Pre-fix:
     * the silent no-op.) Synthetic delete column variant.
     */
    public function testSaveOnTrashedModelThrows(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $post->title = 'Edited while trashed';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('soft-deleted');
        $this->expectExceptionMessageIsOrContains('restore() first');

        $post->save();
    }

    /**
     * Same guard for a user-DECLARED (typed-property) delete column —
     * the snapshot read path is identical.
     */
    public function testSaveOnTrashedModelWithDeclaredColumnThrows(): void
    {
        $post = new SdRenamedPost();
        $post->title = 'Custom';
        $post->save();
        $post->delete();

        $post->title = 'Edited while trashed';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('soft-deleted');

        $post->save();
    }

    /**
     * THE SNAPSHOT-SPACE CONTRACT (regression lock): delete() stores the
     * ENCODED timestamp in `original` — not a raw Carbon. Storing the raw
     * object made getDirty() flag deleted_at dirty forever (Carbon !==
     * encoded string), so every subsequent save() re-wrote a column that
     * did not change. The probe exposes the protected dirty read.
     */
    public function testDeleteLeavesNoPhantomDirtyStamp(): void
    {
        $post = new SdPost();
        $post->title = 'Probe';
        $post->save();

        self::assertSame([], ModelIntrospection::dirtyOf($post), 'a fresh save leaves nothing dirty');

        $post->delete();

        self::assertSame(
            [],
            ModelIntrospection::dirtyOf($post),
            'delete() must not leave deleted_at phantom-dirty in the encoded snapshot space'
        );
    }

    /**
     * restore() clears the timestamp and returns the row to the default
     * scope; restore() on a live (never-deleted) row is a harmless no-op
     * UPDATE that still matches the row — success.
     */
    public function testRestoreLifecycle(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $post->restore();

        self::assertFalse($post->trashed());
        self::assertCount(1, SdPost::all(), 'the restored row is visible again');
    }

    /**
     * THE STALE-ROW CONTRACT: delete() on a model whose row was
     * hard-deleted by another connection throws a StaleRowException —
     * the delete either deletes or errors, never silently no-ops.
     */
    public function testDeleteOnStaleInstanceThrows(): void
    {
        $post = $this->seedPost();

        // Hard-delete the row behind the instance's back.
        $this->connection->table('sd_posts')->where('id', '=', $post->id)->delete();

        $exception = Expectation::throws(
            fn () => $post->delete(),
            StaleRowException::class,
        );

        self::assertSame(SdPost::class, $exception->model);
        self::assertSame('delete', $exception->operation);
        self::assertCount(0, SdPost::newQuery()->withTrashed()->get());
    }

    /**
     * Same contract for restore(): the row was soft-deleted here, then
     * hard-deleted elsewhere — restore() matches 0 rows and throws.
     */
    public function testRestoreOnStaleInstanceThrows(): void
    {
        $post = $this->seedPost();
        $post->delete();

        // Hard-delete the soft-deleted row behind the instance's back.
        $this->connection->table('sd_posts')->where('id', '=', $post->id)->delete();

        $exception = Expectation::throws(
            fn () => $post->restore(),
            StaleRowException::class,
        );

        self::assertSame('restore', $exception->operation);
    }

    /**
     * forceDelete() removes the row entirely — including from withTrashed().
     */
    public function testForceDelete(): void
    {
        $post = $this->seedPost();
        $post->delete();
        self::assertCount(1, SdPost::newQuery()->onlyTrashed()->get());

        $post->forceDelete();

        self::assertCount(0, SdPost::newQuery()->withTrashed()->get());
    }

    /**
     * forceDelete() on a soft-deleted row deleted elsewhere throws a
     * StaleRowException — the hard delete either deletes or errors.
     */
    public function testForceDeleteOnStaleInstanceThrows(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $this->connection->table('sd_posts')->where('id', '=', $post->id)->delete();

        $exception = Expectation::throws(
            fn () => $post->forceDelete(),
            StaleRowException::class,
        );

        self::assertSame('delete', $exception->operation);
    }

    /**
     * THE FORCE-SELECT CONTRACT (regression lock): a narrow caller select
     * still carries the soft-delete column, so a model hydrated from a
     * trashed row reports trashed() honestly. (Pre-fix: the omitted column
     * never hydrated, and trashed() read false for a deleted row.)
     */
    public function testNarrowSelectStillReportsTrashed(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $loaded = SdPost::newQuery()->withTrashed()->select('title')->first();

        self::assertNotNull($loaded);
        self::assertTrue($loaded->trashed(), 'trashed() must survive a select that omits the delete column');
    }

    /**
     * THE FORCE-SELECT GUARD (regression lock): save()'s soft-deleted
     * guard still fires for a model loaded through a narrow select — the
     * UPDATE would carry the auto-scope and match 0 rows while reporting
     * success. (Pre-fix: the guard read a never-hydrated column and
     * passed, silently dropping the write.)
     */
    public function testNarrowSelectSaveGuardStillThrows(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $loaded = SdPost::newQuery()->withTrashed()->select('title')->first();
        self::assertNotNull($loaded);

        $loaded->title = 'Edited while trashed';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('soft-deleted');

        $loaded->save();
    }

    /**
     * A custom deletedAtColumn() renames the backing column — no phantom
     * deleted_at is created and the lifecycle works against renamed_at.
     */
    public function testCustomDeletedAtColumn(): void
    {
        $post = new SdRenamedPost();
        $post->title = 'Custom';
        $post->save();
        $post->delete();

        self::assertTrue($post->trashed());

        // The renamed column carries the timestamp in the database.
        $raw = $this->connection->table('sd_renamed_posts')->get();
        self::assertCount(1, $raw);
        $rawRow = $raw[0] ?? null;
        self::assertNotNull($rawRow);
        self::assertNotNull($rawRow->renamed_at);
    }

    /**
     * THE OVERRIDE CONTRACT (regression lock): a non-null
     * deletedAtColumn() override MUST match a declared #[Column] — an
     * override with no matching declaration is a fail-fast metadata error,
     * not a silently injected phantom column.
     */
    public function testOverrideWithoutDeclaredColumnFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('overrides deletedAtColumn() to [missing_at]');

        \BlueprintAU\Radiant\Metadata\MetadataFactory::for(SdUndeclaredOverridePost::class);
    }

    /**
     * The key-list form of whereKey() composes with the soft-delete scope:
     * the OR-of-key-groups lands INSIDE one outer AND-group, so a
     * soft-deleted row whose key is listed stays excluded. (Pre-fix: the
     * key groups ORed at the TOP level — `(deleted_at IS NULL) OR (id = ?)`
     * — and the trashed row leaked back in whenever its key matched.)
     */
    public function testWhereKeyListExcludesTrashedRows(): void
    {
        $live = $this->seedPost();
        $trashed = $this->seedPost();
        $trashed->delete();

        $found = SdPost::newQuery()->whereKey([$live->id, $trashed->id])->get();

        self::assertCount(1, $found, 'the trashed key must not leak through the list form');
        self::assertNotNull($found[0]);
        self::assertSame($live->id, $found[0]->id);

        // The trashed key ALONE matches nothing — the scope still applies.
        self::assertCount(0, SdPost::newQuery()->whereKey([$trashed->id])->get());
    }

    /**
     * The list form's SQL shape: the scope ANDs against the whole
     * OR-of-key-groups — `(scope) AND ((id = ?) OR (id = ?))` — not a
     * top-level OR chain.
     */
    public function testWhereKeyListSqlShape(): void
    {
        $sql = (new \BlueprintAU\Radiant\Database\Grammars\SqliteGrammar())
            ->compileSelect(SdPost::newQuery()->whereKey([1, 2, 3]));

        self::assertStringContainsString(
            'WHERE ("deleted_at" IS NULL) AND (("id" = ?) OR ("id" = ?) OR ("id" = ?))',
            $sql,
        );
    }

    /**
     * withTrashed() + the list form still sees trashed rows — the scope is
     * stripped first, so the outer AND-group compiles standalone. This is
     * the shape Collection::fresh() relies on.
     */
    public function testWhereKeyListWithTrashedSeesTrashedRows(): void
    {
        $live = $this->seedPost();
        $trashed = $this->seedPost();
        $trashed->delete();

        $found = SdPost::newQuery()->withTrashed()->whereKey([$live->id, $trashed->id])->get();

        self::assertCount(2, $found, 'withTrashed() must keep the trashed key reachable');
    }

    /**
     * An oversized key list (larger than the old 500-key chunk bound) keeps
     * match-ANY semantics: every key ORs inside ONE group. (Regression:
     * an earlier chunking sliced the keys into separate top-level groups,
     * which AND together — a row then needed a key in EVERY chunk to
     * match, so most keys silently matched nothing.)
     */
    public function testWhereKeyOversizedListMatchesAnyKey(): void
    {
        // 501 keys — one past the old chunk bound. Seed 3 posts; the rest
        // of the list is filler keys that match nothing.
        $a = $this->seedPost();
        $b = $this->seedPost();
        $c = $this->seedPost();

        $keys = range(9000, 9499); // 500 filler keys
        $keys[] = (int) $a->id;
        $keys[] = (int) $b->id;
        $keys[] = (int) $c->id;

        $found = SdPost::newQuery()->whereKey($keys)->get();

        self::assertCount(3, $found, 'every seeded key must match — chunks must not AND together');
    }

    /**
     * An oversized key list still respects the soft-delete scope: a
     * trashed key buried past the old chunk bound stays excluded.
     */
    public function testWhereKeyOversizedListExcludesTrashedRows(): void
    {
        $live = $this->seedPost();
        $trashed = $this->seedPost();
        $trashed->delete();

        $keys = range(9000, 9499); // 500 filler keys
        $keys[] = (int) $live->id;
        $keys[] = (int) $trashed->id;

        $found = SdPost::newQuery()->whereKey($keys)->get();

        self::assertCount(1, $found, 'the scope must AND against the whole oversized set');
        self::assertNotNull($found[0]);
        self::assertSame($live->id, $found[0]->id);
    }

    /**
     * Collection::fresh() re-queries through the list form with the scope
     * stripped: a trashed member stays in the collection (re-hydrated),
     * a live member round-trips.
     */
    public function testFreshKeepsTrashedMember(): void
    {
        $live = $this->seedPost();
        $trashed = $this->seedPost();
        $trashed->delete();

        $collection = SdPost::newQuery()->withTrashed()->whereKey([$live->id, $trashed->id])->get();
        $fresh = $collection->fresh();

        self::assertCount(2, $fresh);
    }

    /**
     * Soft deletes are portable: the trait only uses update()/whereKey(),
     * so the lifecycle works on a CSV connection too.
     */
    public function testSoftDeletesOnCsvConnection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'radiant_sd_') ?: (sys_get_temp_dir() . '/radiant_sd_test');
        $handle = fopen($path, 'w');
        \assert($handle !== false);
        fputcsv($handle, ['id', 'title', 'deleted_at'], escape: '');
        fclose($handle);

        try {
            // Target the CSV connection explicitly via the facade — the
            // documented mechanism for a non-default connection. The model's
            // static $connection pin would work too; usingConnection() keeps
            // this test hermetic.
            $this->manager->addConnection('csv-test', ['driver' => 'csv', 'path' => $path, 'readonly' => false]);

            \BlueprintAU\Radiant\Database::usingConnection('csv-test', function (): void {
                // CSV has no auto-increment — the caller assigns the PK
                // (documented CSV behavior; insertGetId() returns null).
                $post = new SdPost();
                $post->id = 7;
                $post->title = 'Csv';
                $post->save();
                $post->delete();

                self::assertTrue($post->trashed());
                self::assertCount(0, SdPost::all(), 'the scoped query hides the soft-deleted row on CSV');

                $post->restore();
                self::assertCount(1, SdPost::all());
            });
        } finally {
            @unlink($path);
            @unlink($path . '.lock');
            @unlink($path . '.radiant-tmp');
        }
    }

    /**
     * On an MTI child the trait's scope column qualifies to its owning
     * table — the synthetic deleted_at lives on the child's partition,
     * and the scope compiles with the qualified name.
     */
    public function testScopeQualifiesPartitionedColumn(): void
    {
        $child = new MtiSoftChild();
        $child->email = 'soft@example.com';
        $child->level = 'lead';
        $child->save();

        // The default scope compiles the qualified column — the query
        // runs (no ambiguous-column error) and finds the live row.
        self::assertCount(1, MtiSoftChild::newQuery()->get());
    }

    /**
     * onlyTrashed() on an MTI child qualifies the partitioned column the
     * same way — the toggle round-trips across the join.
     */
    public function testOnlyTrashedQualifiesPartitionedColumn(): void
    {
        $child = new MtiSoftChild();
        $child->email = 'soft@example.com';
        $child->level = 'lead';
        $child->save();
        $child->delete();

        self::assertCount(1, MtiSoftChild::newQuery()->onlyTrashed()->get());
        self::assertCount(0, MtiSoftChild::newQuery()->get());
    }
}
