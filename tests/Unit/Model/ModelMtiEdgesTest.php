<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiGuidChild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiGuidRoot;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiStampedChild;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiChild;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtiUser;

/**
 * The MTI edge paths beyond {@see \BlueprintAU\Radiant\Tests\Unit\Relations\MtiE2ETest}:
 * caller-assigned (non-auto-increment) root keys, the missing-key guard,
 * the no-dirty update early return, and the multi-table transaction
 * branch (dirty columns spanning BOTH partitions).
 */
final class ModelMtiEdgesTest extends DatabaseTestCase
{
    /**
     * Create the MTI fixture tables — the Guid pair uses a caller-assigned
     * string key (no auto-increment anywhere in the chain); the User/Admin
     * pair uses an auto-increment root.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(MtiGuidRoot::class, MtiGuidChild::class, MtiUser::class, MtiChild::class, MtiStampedChild::class);
    }

    /**
     * A caller-assigned MTI key: the root does NOT auto-generate, so the
     * caller's key rides the insert into BOTH partitions.
     */
    public function testCallerAssignedKeyInsertsIntoBothPartitions(): void
    {
        $admin = new MtiGuidChild();
        $admin->uuid = 'guid-0001';
        $admin->email = 'assigned@example.com';
        $admin->level = 'senior';

        $admin->save();

        $rootRow = $this->connection->table('mti_guid_roots')->where('uuid', '=', 'guid-0001')->first();
        $childRow = $this->connection->table('mti_guid_children')->where('uuid', '=', 'guid-0001')->first();

        self::assertNotNull($rootRow);
        self::assertNotNull($childRow);
        self::assertSame('assigned@example.com', $rootRow->email);
        self::assertSame('senior', $childRow->level);
    }

    /**
     * THE caller-assigned-key guard: an MTI insert without the required
     * key throws — the root INSERT would omit the PK and the descendant
     * partitions would have nothing to link against.
     */
    public function testMissingCallerAssignedKeyThrows(): void
    {
        $admin = new MtiGuidChild();
        $admin->email = 'keyless@example.com';
        $admin->level = 'junior';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('needs its caller-assigned primary key [uuid] set');

        $admin->save();
    }

    /**
     * A no-dirty MTI update early-returns — no queries run, the
     * snapshot re-syncs.
     */
    public function testNoDirtyMtiUpdateEarlyReturns(): void
    {
        $admin = new MtiGuidChild();
        $admin->uuid = 'guid-0002';
        $admin->email = 'clean@example.com';
        $admin->level = 'lead';
        $admin->save();

        // Re-save with NO changes — the update path early-returns.
        $admin->save();

        // The row is untouched and still resolvable. (whereKey, not a bare
        // where: the MTI join makes a bare PK ambiguous in SQL — the
        // builder's own whereKey() qualifies it.)
        $found = MtiGuidChild::newQuery()->whereKey('guid-0002')->first();
        self::assertNotNull($found);
        self::assertSame('lead', $found->level);
    }

    /**
     * Dirty columns spanning BOTH partitions update in ONE transaction —
     * the multi-table branch.
     */
    public function testMultiTableUpdateSpansBothPartitions(): void
    {
        $admin = new MtiGuidChild();
        $admin->uuid = 'guid-0003';
        $admin->email = 'span@example.com';
        $admin->level = 'junior';
        $admin->save();

        // Mutate a root column AND a child column — the dirty set spans
        // two tables, so the update takes the transaction branch.
        $admin->email = 'span2@example.com';
        $admin->level = 'senior';
        $admin->save();

        $rootRow = $this->connection->table('mti_guid_roots')->where('uuid', '=', 'guid-0003')->first();
        $childRow = $this->connection->table('mti_guid_children')->where('uuid', '=', 'guid-0003')->first();

        self::assertNotNull($rootRow);
        self::assertNotNull($childRow);
        self::assertSame('span2@example.com', $rootRow->email);
        self::assertSame('senior', $childRow->level);
    }

    /**
     * A single-partition MTI update (only the child's own column dirty)
     * skips the transaction — one query, one table.
     */
    public function testSinglePartitionUpdateTouchesOneTable(): void
    {
        $admin = new MtiGuidChild();
        $admin->uuid = 'guid-0004';
        $admin->email = 'single@example.com';
        $admin->level = 'junior';
        $admin->save();

        // Only the child column changes.
        $admin->level = 'lead';
        $admin->save();

        $rootRow = $this->connection->table('mti_guid_roots')->where('uuid', '=', 'guid-0004')->first();
        $childRow = $this->connection->table('mti_guid_children')->where('uuid', '=', 'guid-0004')->first();

        self::assertNotNull($rootRow);
        self::assertNotNull($childRow);
        self::assertSame('single@example.com', $rootRow->email, 'root column untouched');
        self::assertSame('lead', $childRow->level);
    }

    /**
     * The MTI delete removes both partitions for a caller-assigned key.
     */
    public function testCallerAssignedKeyDeleteRemovesBothPartitions(): void
    {
        $admin = new MtiGuidChild();
        $admin->uuid = 'guid-0005';
        $admin->email = 'doomed@example.com';
        $admin->level = 'junior';
        $admin->save();

        $admin->delete();

        self::assertSame(0, $this->connection->table('mti_guid_roots')->count());
        self::assertSame(0, $this->connection->table('mti_guid_children')->count());
    }

    /**
     * An AUTO-INCREMENT MTI root generates its id at insert — the root
     * partition omits the key, the descendant copies the generated id,
     * and the leaf instance is stamped with it.
     */
    public function testAutoIncrementRootGeneratesAndPropagatesId(): void
    {
        $admin = new MtiChild();
        $admin->email = 'auto@example.com';
        $admin->level = 'lead';

        $admin->save();
        self::assertGreaterThan(0, $admin->id, 'the generated id must be stamped back onto the leaf');

        $rootRow = $this->connection->table('mti_users')->where('id', '=', $admin->id)->first();
        $childRow = $this->connection->table('mti_admins')->where('id', '=', $admin->id)->first();

        self::assertNotNull($rootRow, 'the root partition must carry the generated id');
        self::assertNotNull($childRow, 'the child partition must link on the generated id');
        self::assertSame('auto@example.com', $rootRow->email);
        self::assertSame('lead', $childRow->level);
    }

    /**
     * A synthetic column (Timestamps' auto-declared stamps carry no PHP
     * property) rides the MTI insert through syntheticValues — the
     * property-less branch of the payload build.
     */
    public function testSyntheticColumnRidesMtiInsert(): void
    {
        $admin = new MtiStampedChild();
        $admin->email = 'synthetic@example.com';
        $admin->level = 'junior';

        $admin->save();

        // email lives on the ROOT table; the stamps live on the child's.
        $rootRow = $this->connection->table('mti_users')->where('email', '=', 'synthetic@example.com')->first();
        self::assertNotNull($rootRow);

        $childRow = $this->connection->table('mti_stamped_children')->where('id', '=', $rootRow->id)->first();
        self::assertNotNull($childRow);
        self::assertNotNull($childRow->created_at, 'the synthetic created_at stamp must ride the insert');
    }

    /**
     * An MTI write on a NON-SQL connection fails fast — the insert splits
     * across tables in one transaction, which only SQL can do.
     */
    public function testMtiInsertOnNonSqlConnectionThrows(): void
    {
        $this->manager->setConnectionConfig('default', [
            'driver' => 'csv',
            'path' => sys_get_temp_dir() . '/radiant_mti_' . uniqid() . '.csv',
        ]);

        $admin = new MtiChild();
        $admin->email = 'csv@example.com';
        $admin->level = 'junior';

        Expectation::throwsWithMessage(
            fn () => $admin->save(),
            \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class,
            'Multi-table inheritance writes require a SQL connection',
        );
    }
}
