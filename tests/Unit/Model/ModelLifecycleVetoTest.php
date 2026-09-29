<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\LvPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\LvSoftPost;

/**
 * The lifecycle-callback contract: attempt events (`saving`, `deleting`,
 * `restoring`) veto on the first listener returning false; success events
 * (`saved`, `deleted`, `restored`) run every listener. The veto paths are
 * the dark branches — nothing is written and `false` is reported.
 */
final class ModelLifecycleVetoTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(LvPost::class, LvSoftPost::class);
    }

    /**
     * A `saving` listener returning false vetoes the INSERT — nothing is
     * written and save() reports false.
     */
    public function testSavingVetoBlocksInsert(): void
    {
        $post = new LvPost();
        $post->title = 'vetoed';
        $post->saving(function (LvPost $model): bool {
            return false;
        });

        self::assertFalse($post->save());
        self::assertSame(0, $this->connection->table('lv_posts')->count());
    }

    /**
     * A `saving` listener returning false vetoes the UPDATE too — the
     * dirty columns never land.
     */
    public function testSavingVetoBlocksUpdate(): void
    {
        $post = new LvPost();
        $post->title = 'original';
        $post->save();

        $post->title = 'changed';
        $post->saving(function (LvPost $model): bool {
            return false;
        });

        self::assertFalse($post->save());

        $fresh = LvPost::newQuery()->find($post->id);
        self::assertNotNull($fresh);
        self::assertSame('original', $fresh->title);
    }

    /**
     * A `saving` listener returning true (or anything non-false) does NOT
     * veto — only strict `false` is a veto.
     */
    public function testSavingTrueDoesNotVeto(): void
    {
        $post = new LvPost();
        $post->title = 'allowed';
        $post->saving(function (LvPost $model): bool {
            return true;
        });

        self::assertTrue($post->save());
        self::assertSame(1, $this->connection->table('lv_posts')->count());
    }

    /**
     * A `deleting` listener returning false vetoes the delete — the row
     * stays and delete() reports false.
     */
    public function testDeletingVetoBlocksDelete(): void
    {
        $post = new LvPost();
        $post->title = 'kept';
        $post->save();

        $post->deleting(function (LvPost $model): bool {
            return false;
        });

        self::assertFalse($post->delete());
        self::assertSame(1, $this->connection->table('lv_posts')->count());
    }

    /**
     * Listeners run in REGISTRATION order and the veto stops at the first
     * false — later listeners never see the event.
     */
    public function testVetoStopsAtFirstFalse(): void
    {
        $post = new LvPost();
        $post->title = 'ordered';
        $post->save();

        $calls = [];

        $post->deleting(function (LvPost $model) use (&$calls): bool {
            $calls[] = 'first';
            return false;
        });
        $post->deleting(function (LvPost $model) use (&$calls): bool {
            $calls[] = 'second';
            return true;
        });

        self::assertFalse($post->delete());
        self::assertSame(['first'], $calls);
    }

    /**
     * Success listeners run EVERY listener, in order, after the write.
     */
    public function testSavedRunsEveryListenerInOrder(): void
    {
        $post = new LvPost();
        $post->title = 'success';

        $calls = [];

        $post->saved(function (LvPost $model) use (&$calls): void {
            $calls[] = 'first:' . $model->id;
        });
        $post->saved(function (LvPost $model) use (&$calls): void {
            $calls[] = 'second:' . $model->id;
        });

        self::assertTrue($post->save());
        self::assertSame(['first:1', 'second:1'], $calls);
    }

    /**
     * The `deleted` success event fires after a successful hard delete.
     */
    public function testDeletedFiresAfterHardDelete(): void
    {
        $post = new LvPost();
        $post->title = 'gone';
        $post->save();

        $fired = null;
        $post->deleted(function (LvPost $model) use (&$fired): void {
            $fired = $model->id;
        });

        self::assertTrue($post->delete());
        self::assertSame($post->id, $fired);
        self::assertSame(0, $this->connection->table('lv_posts')->count());
    }

    /**
     * A `deleting` veto also blocks forceDelete() — the attempt event
     * runs before the hard DELETE.
     */
    public function testDeletingVetoBlocksForceDelete(): void
    {
        $post = new LvSoftPost();
        $post->title = 'pinned';
        $post->save();

        $post->deleting(function (LvSoftPost $model): bool {
            return false;
        });

        self::assertFalse($post->forceDelete());
        self::assertSame(1, $this->connection->table('lv_soft_posts')->count());
    }

    /**
     * A `restoring` listener returning false vetoes the restore — the
     * row stays soft-deleted.
     */
    public function testRestoringVetoBlocksRestore(): void
    {
        $post = new LvSoftPost();
        $post->title = 'held';
        $post->save();
        $post->delete();

        $post->restoring(function (LvSoftPost $model): bool {
            return false;
        });

        self::assertFalse($post->restore());
        self::assertTrue($post->trashed());
        self::assertSame(1, LvSoftPost::newQuery()->withTrashed()->count());
        self::assertSame(0, LvSoftPost::newQuery()->count());
    }

    /**
     * The `restored` success event fires after a successful restore.
     */
    public function testRestoredFiresAfterRestore(): void
    {
        $post = new LvSoftPost();
        $post->title = 'back';
        $post->save();
        $post->delete();

        $fired = false;
        $post->restored(function (LvSoftPost $model) use (&$fired): void {
            $fired = true;
        });

        self::assertTrue($post->restore());
        self::assertTrue($fired);
        self::assertFalse($post->trashed());
    }
}
