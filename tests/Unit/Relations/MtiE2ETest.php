<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Fixture: the MTI root.
 */
#[Table(name: 'mti_users')]
class MtiUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's email.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;
}

/**
 * Fixture: the MTI child — own table, derived shared key.
 */
#[Table(name: 'mti_admins')]
class MtiChild extends MtiUser
{
    /**
     * The admin level (on the child's own table).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}

/**
 * End-to-end MTI tests on live SQLite: the split write, the joined read,
 * partitioned updates, and the leaf-first delete.
 */
final class MtiE2ETest extends DatabaseTestCase
{
    /**
     * Create the MTI parent/child tables from the models' attributes —
     * the child blueprint carries the derived shared-key FK.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(MtiUser::class, MtiChild::class);
    }

    /**
     * The split insert writes BOTH tables — root first with the generated
     * id copied into the child's PK.
     */
    public function testSplitInsertWritesBothTables(): void
    {
        $admin = new MtiChild();
        $admin->email = 'alicia@example.com';
        $admin->level = 'senior';
        $admin->save();

        self::assertSame(1, $admin->id);

        $userRow = $this->connection->table('mti_users')->where('id', '=', 1)->first();
        $adminRow = $this->connection->table('mti_admins')->where('id', '=', 1)->first();

        self::assertNotNull($userRow);
        self::assertNotNull($adminRow);
        self::assertSame('alicia@example.com', $userRow->email);
        self::assertSame('senior', $adminRow->level);
    }

    /**
     * The joined read hydrates BOTH levels' columns into one model — the
     * virtual row.
     */
    public function testJoinedReadHydratesBothLevels(): void
    {
        $admin = new MtiChild();
        $admin->email = 'ben@example.com';
        $admin->level = 'junior';
        $admin->save();

        $found = MtiChild::find($admin->id);

        self::assertNotNull($found);
        self::assertSame('ben@example.com', $found->email, 'inherited column from the parent table');
        self::assertSame('junior', $found->level, 'own column from the child table');
    }

    /**
     * A root-level query (the parent model) sees the root table's row —
     * both models share the identity; the child only ADDS columns.
     */
    public function testParentQuerySeesRootRow(): void
    {
        $admin = new MtiChild();
        $admin->email = 'carol@example.com';
        $admin->level = 'lead';
        $admin->save();

        // The parent model queries ONLY its own table — the root row is
        // there even though the child row exists.
        $user = MtiUser::find($admin->id);

        self::assertNotNull($user);
        self::assertSame('carol@example.com', $user->email);
    }

    /**
     * A partitioned update: the root column and the child column each
     * update their own table.
     */
    public function testPartitionedUpdate(): void
    {
        $admin = new MtiChild();
        $admin->email = 'dave@example.com';
        $admin->level = 'junior';
        $admin->save();

        $admin->email = 'dave2@example.com';
        $admin->level = 'senior';
        $admin->save();

        $found = MtiChild::find($admin->id);

        self::assertNotNull($found);
        self::assertSame('dave2@example.com', $found->email);
        self::assertSame('senior', $found->level);
    }

    /**
     * The leaf-first delete removes BOTH rows.
     */
    public function testDeleteRemovesBothRows(): void
    {
        $admin = new MtiChild();
        $admin->email = 'erin@example.com';
        $admin->level = 'senior';
        $admin->save();

        $admin->delete();

        self::assertNull(MtiChild::find(1));
        self::assertSame(0, $this->connection->table('mti_users')->count());
        self::assertSame(0, $this->connection->table('mti_admins')->count());
    }
}
