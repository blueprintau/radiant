<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MpForeignPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MpMtiChild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MpPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MpSoftPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;
use Carbon\Carbon;

/**
 * Coverage for the {@see \BlueprintAU\Radiant\MassPrunable} trait: bulk
 * drain-by-deletion (no cursor), the pluck-count loop control, trashed
 * purging, the MTI-child guard, and the shared guard contract.
 */
final class MassPrunableTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables from METADATA.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            MpPost::class,
            MpSoftPost::class,
            MpMtiChild::class,
            MtiUser::class,
        );
    }

    /**
     * A fresh expiry cutoff — rows created before it are prune
     * candidates.
     *
     * @return Carbon The cutoff instant.
     */
    private function expiry(): Carbon
    {
        $expiry = Carbon::parse('2026-06-01 12:00:00');

        MpPost::$expiry = $expiry;
        MpSoftPost::$expiry = $expiry;

        return $expiry;
    }

    /**
     * Seed one expired MpPost and return it.
     *
     * @param  string  $title
     * @return MpPost The saved model.
     */
    private function seedPost(string $title): MpPost
    {
        $model = new MpPost();
        $model->title = $title;
        $model->createdAt = Carbon::parse('2026-01-01 00:00:00');
        $model->save();

        return $model;
    }

    /**
     * Seed one expired MpSoftPost and return it.
     *
     * @param  string  $title
     * @return MpSoftPost The saved model.
     */
    private function seedSoftPost(string $title): MpSoftPost
    {
        $model = new MpSoftPost();
        $model->title = $title;
        $model->createdAt = Carbon::parse('2026-01-01 00:00:00');
        $model->save();

        return $model;
    }

    /**
     * Seed one SURVIVING MpPost row (created after the cutoff).
     *
     * @return MpPost The saved model.
     */
    private function seedSurviving(): MpPost
    {
        $model = new MpPost();
        $model->title = 'fresh';
        $model->createdAt = Carbon::parse('2026-09-01 00:00:00');
        $model->save();

        return $model;
    }

    /**
     * pruneAll() deletes exactly the expired rows via ONE bulk delete per
     * chunk — survivors stay.
     */
    public function testBulkPruneDeletesExpiredAndReportsCount(): void
    {
        $this->expiry();

        $this->seedPost('old-a');
        $this->seedPost('old-b');
        $this->seedPost('old-c');
        $this->seedSurviving();

        self::assertSame(3, MpPost::pruneAll(chunkSize: 2));
        self::assertCount(1, MpPost::newQuery()->get());
        self::assertSame('fresh', MpPost::newQuery()->first()?->title);
    }

    /**
     * EMPTY candidate set returns 0 immediately.
     */
    public function testBulkPruneOnEmptySetReturnsZero(): void
    {
        $this->expiry();

        $this->seedSurviving();

        self::assertSame(0, MpPost::pruneAll());
    }

    /**
     * Drain-by-deletion termination: every chunk reads only FRESH
     * candidates (deleted rows vanish from scope), so chunks of 1 over N
     * rows terminate after exactly N rounds and prune all N.
     */
    public function testDrainTerminatesAcrossChunks(): void
    {
        $this->expiry();

        foreach (['a', 'b', 'c', 'd'] as $title) {
            $this->seedPost($title);
        }

        self::assertSame(4, MpPost::pruneAll(chunkSize: 1));
        self::assertSame(0, MpPost::newQuery()->count());
    }

    /**
     * A soft-deletable MassPrunable purges TRASHED rows too — the
     * candidate read rides withTrashed() and the bulk delete reaches
     * them (builder deletes bypass instance hooks entirely).
     */
    public function testBulkPrunePurgesTrashedRows(): void
    {
        $this->expiry();

        $trashed = $this->seedSoftPost('trashed-old');
        $trashed->delete();
        self::assertTrue($trashed->trashed());

        $this->seedSoftPost('live-old');

        self::assertSame(2, MpSoftPost::pruneAll());
        self::assertSame(0, MpSoftPost::newQuery()->withTrashed()->count());
    }

    /**
     * MTI children fail fast — a bulk DELETE would orphan the ancestor
     * table rows; the message points at Prunable.
     */
    public function testMtiChildIsRejected(): void
    {
        $exception = Expectation::throwsWithMessage(
            fn () => MpMtiChild::pruneAll(),
            \LogicException::class,
            'MassPrunable cannot prune MTI children',
        );

        self::assertStringContainsString('Prunable', $exception->getMessage());
    }

    /**
     * The shared guard contract fires identically on the mass path:
     * chunkSize < 1, composite PKs (asserted on PrunableTest via the
     * shared trait), foreign builders, and pre-set limits all fail
     * BEFORE any statement runs.
     */
    public function testChunkSizeGuardFiresBeforeAnything(): void
    {
        $this->expiry();

        $this->seedPost('target');
        $this->seedSurviving();

        Expectation::throwsWithMessage(
            fn () => MpPost::pruneAll(chunkSize: 0),
            \InvalidArgumentException::class,
            'Chunk size must be at least 1',
        );

        // Nothing was deleted — the guard threw at entry (both rows still
        // present).
        self::assertSame(2, MpPost::newQuery()->count());
    }

    /**
     * A foreign prunable() builder is rejected on the mass path too.
     */
    public function testForeignBuilderRejectedOnMassPath(): void
    {
        Expectation::throwsWithMessage(
            fn () => MpForeignPost::pruneAll(),
            \LogicException::class,
            'returned one for',
        );
    }
}
