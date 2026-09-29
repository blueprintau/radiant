<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiGuidChild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MtiGuidRoot;

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
     * string key (no auto-increment anywhere in the chain).
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(MtiGuidRoot::class, MtiGuidChild::class);
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

        self::assertTrue($admin->save());

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
     * A no-dirty MTI update early-returns true — no queries run, the
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
        self::assertTrue($admin->save());

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
        self::assertTrue($admin->save());

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
        self::assertTrue($admin->save());

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

        self::assertTrue($admin->delete());

        self::assertSame(0, $this->connection->table('mti_guid_roots')->count());
        self::assertSame(0, $this->connection->table('mti_guid_children')->count());
    }
}
