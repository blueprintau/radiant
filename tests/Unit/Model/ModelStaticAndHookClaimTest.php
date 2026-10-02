<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\HookClaimPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\HookClaimUpdatePost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiHookClaimChild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiInsertClaimChild;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

/**
 * The Model static-query shortcuts (limit/offset/having) and the
 * write-hook CLAIM arms — a trait hook returning bool owns the write,
 * so the INSERT/UPDATE never reaches the database.
 */
final class ModelStaticAndHookClaimTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            HookClaimPost::class,
            HookClaimUpdatePost::class,
            MtiUser::class,
            MtiHookClaimChild::class,
            MtiInsertClaimChild::class,
        );
    }

    // ---- Static query shortcuts ----

    /**
     * Model::limit() starts a bounded query — the static shortcut
     * delegates to the builder.
     */
    #[DoesNotPerformAssertions]
    public function testStaticLimitBuildsBoundedQuery(): void
    {
        // The call itself is the subject: the builder accepts the limit
        // without error (execution needs rows; the builder shape is the arm).
        HookClaimPost::limit(5);
    }

    /**
     * Model::offset() starts an offset query — the static shortcut.
     */
    #[DoesNotPerformAssertions]
    public function testStaticOffsetBuildsOffsetQuery(): void
    {
        HookClaimPost::offset(10);
    }

    /**
     * Model::having() starts a having clause — the static shortcut.
     */
    #[DoesNotPerformAssertions]
    public function testStaticHavingBuildsHavingQuery(): void
    {
        HookClaimPost::having('id', WhereOperator::Gt, 0);
    }

    // ---- Insert-claim hook ----

    /**
     * A trait hook returning TRUE claims the INSERT — the write never
     * reaches the database and the model reports success.
     */
    public function testInsertClaimHookOwnsTheWrite(): void
    {
        $post = new HookClaimPost();
        $post->title = 'claimed';

        self::assertTrue($post->save());

        // The hook saw the model.
        self::assertCount(1, HookClaimPost::$claimedInserts);

        // The write never landed — the table stays empty.
        self::assertSame(0, $this->connection->table('hook_claim_posts')->count());
    }

    // ---- Update-claim hook (single-table) ----

    /**
     * A trait hook returning TRUE claims the UPDATE — the dirty columns
     * never reach the database.
     */
    public function testUpdateClaimHookOwnsTheWrite(): void
    {
        $post = new HookClaimUpdatePost();
        $post->id = 1;
        $post->title = 'before';
        $post->save();

        HookClaimUpdatePost::$claimedUpdates = [];

        $post->title = 'after';
        self::assertTrue($post->save());

        // The hook saw the model.
        self::assertCount(1, HookClaimUpdatePost::$claimedUpdates);

        // The write never landed — the row keeps its original title.
        $row = $this->connection->table('hook_claim_updates')->where('id', '=', 1)->first();
        self::assertNotNull($row);
        self::assertSame('before', $row->title);
    }

    // ---- Update-claim hook (MTI) ----

    /**
     * A trait hook returning TRUE claims the MTI UPDATE — the partition
     * split never runs.
     */
    public function testMtiUpdateClaimHookOwnsTheWrite(): void
    {
        $admin = new MtiHookClaimChild();
        $admin->email = 'claim@example.com';
        $admin->level = 'junior';
        $admin->save();

        MtiHookClaimChild::$claimedUpdates = [];

        $admin->level = 'senior';
        self::assertTrue($admin->save());

        // The hook saw the model.
        self::assertCount(1, MtiHookClaimChild::$claimedUpdates);

        // The write never landed — the child partition keeps its level.
        $row = $this->connection->table('mti_hook_claim_children')->first();
        self::assertNotNull($row);
        self::assertSame('junior', $row->level);
    }

    // ---- MTI insert-claim hook ----

    /**
     * A trait hook returning TRUE claims the MTI INSERT — the
     * multi-table transaction never opens.
     */
    public function testMtiInsertClaimHookOwnsTheWrite(): void
    {
        $admin = new MtiInsertClaimChild();
        $admin->email = 'insert-claim@example.com';
        $admin->level = 'lead';

        self::assertTrue($admin->save());

        // The write never landed — neither partition has a row.
        self::assertSame(0, $this->connection->table('mti_users')->count());
        self::assertSame(0, $this->connection->table('mti_insert_claim_children')->count());
    }

    // ---- Composite write guard: partial key ----

    /**
     * A composite-PK write with ONE null key component fails fast — the
     * partial tuple would compile to `WHERE country IS NULL`.
     */
    public function testCompositeWriteWithNullKeyComponentThrows(): void
    {
        $region = new \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion();
        $region->id = 1;
        $region->name = 'Partial';

        // Force the UPDATE branch without the second key component.
        $ref = new \ReflectionProperty(\BlueprintAU\Radiant\Model::class, 'exists');
        $ref->setValue($region, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('cannot target its row');

        $region->name = 'Edited';
        $region->save();
    }

    // ---- relationName: factory called from a plain function ----

    /**
     * A relation factory called from a CLASS-LESS frame stamps no name —
     * the backtrace walk bails at the first plain function.
     */
    public function testRelationFactoryFromPlainFunctionStampsNoName(): void
    {
        $user = new \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser();
        $user->email = 'plain@example.com';

        $relation = relation_from_plain_function($user);

        // The relation still builds — only the eager-cache stamp is absent.
        self::assertInstanceOf(\BlueprintAU\Radiant\Relations\HasMany::class, $relation);
    }
}

/**
 * A plain (class-less) function — the class-less backtrace frame.
 *
 * @return \BlueprintAU\Radiant\Relations\HasMany<\BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost>
 */
function relation_from_plain_function(\BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser $user): \BlueprintAU\Radiant\Relations\HasMany
{
    return $user->posts();
}
