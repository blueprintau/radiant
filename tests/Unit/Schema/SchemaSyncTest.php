<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use PHPUnit\Framework\TestCase;

/**
 * A sync-able model — exercises {@see Blueprint::fromMetadata()}.
 */
#[Table(name: 'sync_users')]
class SyncUser extends \BlueprintAU\Radiant\Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A unique email column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;

    /**
     * An indexed FK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, index: true)]
    public int $roleId;
}

/**
 * A composite-constraint model — exercises the class-level attribute path.
 */
#[ForeignKey(columns: ['roleId'], references: 'sync_users', referencesColumns: ['id'])]
#[Table(name: 'sync_posts')]
class SyncPost extends \BlueprintAU\Radiant\Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The FK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $roleId;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}

/**
 * An MTI child of SyncUser — exercises the derived-key + emitted-FK path.
 */
#[Table(name: 'sync_admins')]
class SyncAdmin extends SyncUser
{
    /**
     * A column that belongs on the child's own table.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;
}

/**
 * A model with TWO class-level uniques — proves unique index names are
 * derived per constraint (a hardcoded name would emit two CREATE UNIQUE
 * INDEX statements with the same name; the second fails at the DB).
 */
#[Unique(columns: ['regionId', 'country'])]
#[Unique(columns: ['country', 'title'])]
#[Table(name: 'sync_multi_unique')]
class MultiUnique extends \BlueprintAU\Radiant\Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region column — part of the first unique.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    /**
     * The country column — part of both uniques.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;

    /**
     * The title column — part of the second unique.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}

/**
 * A metadata error fixture: two constraints covering the SAME columns —
 * they derive the same index name, which fromMetadata() rejects as a
 * duplicate declaration.
 */
#[Unique(columns: ['regionId', 'country'])]
#[Unique(columns: ['regionId', 'country'])]
#[Table(name: 'sync_dup_constraint')]
class DuplicateConstraint extends \BlueprintAU\Radiant\Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    /**
     * The country column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;
}

/**
 * A COMPOSITE-PK model — a `foreign:` model-class reference to it must be
 * rejected (no single default column to target).
 */
#[Table(name: 'sync_composite_pk')]
class SyncCompositePk extends \BlueprintAU\Radiant\Model
{
    /**
     * First PK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $tenantId;

    /**
     * Second PK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $resourceId;
}

/**
 * A model with an EXPLICIT unique name — #[Unique(name: ...)] overrides
 * the column-derived default.
 */
#[Unique(columns: ['regionId', 'country'], name: 'region_lock')]
#[Table(name: 'sync_named_unique')]
class NamedUnique extends \BlueprintAU\Radiant\Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The region column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    /**
     * The country column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 2)]
    public string $country;
}

/**
 * §14d end-to-end: `fromMetadata()` → `SchemaDiffer` → `apply()` on live
 * SQLite, mirroring the host's sync command from the plan (§15).
 */
final class SchemaSyncTest extends TestCase
{
    /**
     * The live SQLite connection.
     *
     * @var SqlConnection
     */
    private SqlConnection $connection;

    /**
     * Build a manager with a :memory: SQLite connection.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        \BlueprintAU\Radiant\Database::setManager($manager);
        $this->connection = $manager->sqlConnection();
    }

    /**
     * Reset the static facade so other tests are unaffected.
     */
    protected function tearDown(): void
    {
        \BlueprintAU\Radiant\Database::setManager(new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        parent::tearDown();
    }

    /**
     * fromMetadata() maps every #[Column] (including flag-derived
     * unique/index) onto a blueprint whose DDL round-trips.
     */
    public function testFromMetadataProducesValidDdl(): void
    {
        $blueprint = Blueprint::fromMetadata(SyncUser::class);

        $this->connection->create($blueprint);

        $live = $this->connection->schemaInspector->table('sync_users');
        $names = array_column($live->columns, 'name');

        self::assertSame(['id', 'email', 'roleId'], $names);
        self::assertTrue($live->columns[0]['primaryKey']);
        self::assertFalse($live->columns[0]['nullable']);
    }

    /**
     * fromMetadata() emits the class-level #[ForeignKey] as a real
     * constraint, verified live.
     */
    public function testFromMetadataEmitsForeignKeys(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncUser::class));
        $this->connection->create(Blueprint::fromMetadata(SyncPost::class));

        $live = $this->connection->schemaInspector->table('sync_posts');

        self::assertCount(1, $live->foreignKeys);
        self::assertSame(['roleId'], $live->foreignKeys[0]['columns']);
        self::assertSame('sync_users', $live->foreignKeys[0]['referencesTable']);
        self::assertSame(['id'], $live->foreignKeys[0]['referencesColumns']);
    }

    /**
     * fromMetadata() rejects a column-less model — nothing to sync.
     */
    public function testFromMetadataRejectsTablelessModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('owns no table');
        Blueprint::fromMetadata(\BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ConcreteBase::class);
    }

    /**
     * An MTI child's blueprint: own columns + the derived non-autoincrement
     * key + the factory-emitted FK to the parent table — all verified live.
     */
    public function testFromMetadataEmitsMtiChildForeignKey(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncUser::class));
        $this->connection->create(Blueprint::fromMetadata(SyncAdmin::class));

        $live = $this->connection->schemaInspector->table('sync_admins');
        $names = array_column($live->columns, 'name');

        // The child table holds ONLY the derived key + the child's own
        // columns — the inherited columns live on sync_users.
        self::assertSame(['id', 'level'], $names);

        // The key is NOT auto-increment on the child — only the root
        // generates. The live inspector's column shape reports
        // `autoIncrement` via the DDL clause; assert through the raw SQL
        // instead (the shape has no autoIncrement key by design).
        $ddl = (string) $this->connection->selectSql(
            "SELECT sql FROM sqlite_master WHERE name = 'sync_admins'",
        )->first()->sql;
        self::assertStringNotContainsString('AUTOINCREMENT', $ddl);
        self::assertStringContainsString('FOREIGN KEY ("id") REFERENCES "sync_users" ("id")', $ddl);

        self::assertCount(1, $live->foreignKeys);
        self::assertSame(['id'], $live->foreignKeys[0]['columns']);
        self::assertSame('sync_users', $live->foreignKeys[0]['referencesTable']);
        self::assertSame(['id'], $live->foreignKeys[0]['referencesColumns']);
    }

    /**
     * The sync loop converges for MTI tables: create both, diff again → empty.
     */
    public function testMtiTablesConverge(): void
    {
        $desired = [
            Blueprint::fromMetadata(SyncUser::class),
            Blueprint::fromMetadata(SyncAdmin::class),
        ];

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff($desired);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        self::assertSame([], (new SchemaDiffer($this->connection->schemaInspector))->diff($desired));
    }

    /**
     * The differ classifies a fresh schema as creates (non-destructive).
     */
    public function testDiffClassifiesCreates(): void
    {
        $differ = new SchemaDiffer($this->connection->schemaInspector);

        $changes = $differ->diff([
            Blueprint::fromMetadata(SyncUser::class),
        ]);

        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::CreateTable, $changes[0]->operation);
        self::assertFalse($changes[0]->destructive);
        self::assertSame('sync_users', $changes[0]->table);
    }

    /**
     * The differ classifies an undeclared live table as a destructive drop.
     */
    public function testDiffClassifiesDrops(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncUser::class));

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        $changes = $differ->diff([]); // desired state: nothing.

        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::DropTable, $changes[0]->operation);
        self::assertTrue($changes[0]->destructive);
    }

    /**
     * The differ orders creates first, alters second, drops last — the
     * rename-safety guarantee.
     */
    public function testDiffOrdering(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncPost::class));

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        // The posts blueprint gains an `email` column — an AddColumn alter.
        $postsWithAlter = Blueprint::fromMetadata(SyncPost::class)
            ->column(ColumnType::String, 'email', length: 255);

        $changes = $differ->diff([
            Blueprint::fromMetadata(SyncUser::class), // create
            $postsWithAlter, // alter second
        ]);

        self::assertSame('sync_users', $changes[0]->table); // create first
        self::assertSame(SchemaOperation::CreateTable, $changes[0]->operation);
        self::assertSame('sync_posts', $changes[1]->table); // alter second
        self::assertSame(SchemaOperation::AddColumn, $changes[1]->operation);
    }

    /**
     * The full sync loop: diff → apply → diff again is empty (converged).
     */
    public function testDiffApplyConverges(): void
    {
        $differ = new SchemaDiffer($this->connection->schemaInspector);

        $desired = [
            Blueprint::fromMetadata(SyncUser::class),
            Blueprint::fromMetadata(SyncPost::class),
        ];

        foreach ($differ->diff($desired) as $change) {
            $this->connection->apply($change);
        }

        self::assertSame([], $differ->diff($desired));
    }

    /**
     * apply() dispatches every SchemaOperation case correctly.
     */
    public function testApplyDispatchesAllOperations(): void
    {
        // CreateTable.
        $create = new \BlueprintAU\Radiant\Database\Schema\SchemaChange(
            'sync_users',
            SchemaOperation::CreateTable,
            Blueprint::fromMetadata(SyncUser::class),
            false,
            'create',
        );
        $this->connection->apply($create);
        self::assertTrue($this->connection->schemaInspector->hasTable('sync_users'));

        // AddColumn.
        $add = new \BlueprintAU\Radiant\Database\Schema\SchemaChange(
            'sync_users',
            SchemaOperation::AddColumn,
            (new Blueprint('sync_users'))->column(ColumnType::String, 'nickname', length: 64, nullable: true),
            false,
            'add',
        );
        $this->connection->apply($add);
        $names = array_column($this->connection->schemaInspector->table('sync_users')->columns, 'name');
        self::assertContains('nickname', $names);

        // DropColumn (SQLite supports ALTER DROP only in modern builds; the
        // dialect gate decides — expect either the column gone or the gate).
        $drop = new \BlueprintAU\Radiant\Database\Schema\SchemaChange(
            'sync_users',
            SchemaOperation::DropColumn,
            (new Blueprint('sync_users'))->dropColumn('nickname'),
            true,
            'drop',
        );

        try {
            $this->connection->apply($drop);
            $names = array_column($this->connection->schemaInspector->table('sync_users')->columns, 'name');
            self::assertNotContains('nickname', $names);
        } catch (\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException) {
            $this->addToAssertionCount(1); // dialect gate fired — acceptable.
        }

        // DropTable.
        $dropTable = new \BlueprintAU\Radiant\Database\Schema\SchemaChange(
            'sync_users',
            SchemaOperation::DropTable,
            new Blueprint('sync_users'),
            true,
            'drop',
        );
        $this->connection->apply($dropTable);
        self::assertFalse($this->connection->schemaInspector->hasTable('sync_users'));
    }

    /**
     * The SQLite inspector reads indexes back (unique from the inline
     * constraint, plain from the index flag).
     */
    public function testSqliteInspectorReadsIndexes(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncUser::class));

        $live = $this->connection->schemaInspector->table('sync_users');

        $uniqueColumns = array_map(
            fn (array $index) => $index['columns'],
            array_values(array_filter($live->indexes, fn (array $index) => $index['unique'])),
        );
        $plainColumns = array_map(
            fn (array $index) => $index['columns'],
            array_values(array_filter($live->indexes, fn (array $index) => !$index['unique'])),
        );

        self::assertContains(['email'], $uniqueColumns);
        self::assertContains(['roleId'], $plainColumns);
    }

    /**
     * A column rename (title → slug) is an add+drop on one table — the
     * alter is flagged possibleRename and the description names both sides,
     * instead of the old misleading DropColumn-only label.
     */
    public function testColumnRenameRaisesAdvisory(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncPost::class));

        // Desired state: title renamed to slug (same type — pure rename).
        $desired = Blueprint::fromMetadata(SyncPost::class)
            ->dropColumn('title')
            ->column(ColumnType::String, 'slug', length: 64);

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff([$desired]);

        self::assertCount(1, $changes);
        self::assertTrue($changes[0]->possibleRename);
        self::assertTrue($changes[0]->destructive);
        self::assertStringContainsString('POSSIBLE RENAME', $changes[0]->description);
        self::assertStringContainsString('[title]', $changes[0]->description);
        self::assertStringContainsString('[slug]', $changes[0]->description);
    }

    /**
     * A pure add (no drops) raises NO advisory — the flag is rename-shape
     * only, not a general destructive marker.
     */
    public function testPureAddRaisesNoAdvisory(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncPost::class));

        $desired = Blueprint::fromMetadata(SyncPost::class)
            ->column(ColumnType::String, 'slug', length: 64);

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff([$desired]);

        self::assertCount(1, $changes);
        self::assertFalse($changes[0]->possibleRename);
        self::assertFalse($changes[0]->destructive);
        self::assertStringNotContainsString('RENAME', $changes[0]->description);
    }

    /**
     * A table rename (posts → articles, same shape) pairs the CreateTable
     * with the DropTable: both sides carry renameOf, operations unchanged.
     */
    public function testTableRenamePairsCreateAndDrop(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncPost::class));

        // Desired: the same SHAPE re-declared under a new table name. The
        // blueprint is bound to its table, so the rename is expressed by a
        // blueprint whose table genuinely IS the new name (fromMetadata()
        // always binds the model's declared table, so this one is built by
        // hand to mirror SyncPost's shape).
        $articles = (new Blueprint('sync_articles'))
            ->id()
            ->column(ColumnType::BigInt, 'roleId', index: true)
            ->column(ColumnType::String, 'title', length: 64)
            ->foreignKey(['roleId'], 'sync_users', ['id']);
        $desired = [$articles];

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff($desired);

        self::assertCount(2, $changes);

        $create = $changes[0];
        $drop = $changes[1];

        self::assertSame(SchemaOperation::CreateTable, $create->operation);
        // Each side's renameOf points at the OTHER table — the pair.
        self::assertSame('sync_posts', $create->renameOf);
        self::assertStringContainsString('POSSIBLE RENAME', $create->description);

        self::assertSame(SchemaOperation::DropTable, $drop->operation);
        self::assertTrue($drop->destructive);
        self::assertSame('sync_articles', $drop->renameOf);
        self::assertStringContainsString('POSSIBLE RENAME', $drop->description);
    }

    /**
     * An UNRELATED new table (no column overlap with the dropped one) is
     * NOT flagged — the advisory must not cry wolf on add+delete refactors.
     */
    public function testUnrelatedTablePairRaisesNoAdvisory(): void
    {
        $this->connection->create(Blueprint::fromMetadata(SyncPost::class));

        // An unrelated shape: only `id` shared with SyncPost — 1/3 < 50%.
        $desired = [new Blueprint('sync_photos')
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'path', length: 255)
            ->column(ColumnType::String, 'caption', length: 255)];

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff($desired);

        self::assertCount(2, $changes);

        self::assertNull($changes[0]->renameOf);
        self::assertNull($changes[1]->renameOf);
        self::assertStringNotContainsString('RENAME', $changes[0]->description);
        self::assertStringNotContainsString('RENAME', $changes[1]->description);
    }

    /**
     * Two class-level #[Unique] attributes compile DISTINCT unique-index
     * names (derived from the covered columns with a `_unique` suffix —
     * the suffix says WHAT the index is and cannot collide with a
     * #[CompositeIndex] over the same columns). A hardcoded name would
     * emit two CREATE UNIQUE INDEX statements with the same name and the
     * second would fail at the database.
     */
    public function testMultipleUniquesGetDistinctNames(): void
    {
        $blueprint = Blueprint::fromMetadata(MultiUnique::class);

        $names = array_column($blueprint->getIndexes(), 'name');

        self::assertCount(2, $names);
        self::assertSame(
            ['sync_multi_unique_regionId_country_unique', 'sync_multi_unique_country_title_unique'],
            $names,
        );

        // And the DDL round-trips on a live connection — two distinct
        // CREATE UNIQUE INDEX statements, no name collision.
        $this->connection->create($blueprint);
        $live = $this->connection->schemaInspector->table('sync_multi_unique');
        self::assertCount(2, $live->indexes);
    }

    /**
     * An explicit #[Unique(name: ...)] is the WHOLE index name — used
     * verbatim in the DDL, no table prefix, no `_index` suffix. The
     * `{table}_{name}_index` rendering is for DERIVED names only.
     */
    public function testExplicitUniqueNameWins(): void
    {
        $blueprint = Blueprint::fromMetadata(NamedUnique::class);

        $names = array_column($blueprint->getIndexes(), 'name');

        self::assertSame(['region_lock'], $names);

        // Live DDL: the name appears verbatim — the grammar did NOT
        // decorate it.
        $this->connection->create($blueprint);
        $live = $this->connection->schemaInspector->table('sync_named_unique');
        self::assertSame(['region_lock'], array_column($live->indexes, 'name'));
    }

    /**
     * Two constraint declarations covering the SAME columns derive the
     * same name — a genuine duplicate — and fail fast at blueprint build
     * instead of as a broken CREATE INDEX at DDL time.
     */
    public function testDuplicateIndexNamesFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('two indexes named [sync_dup_constraint_regionId_country_unique]');

        Blueprint::fromMetadata(DuplicateConstraint::class);
    }

    /**
     * A single-column `foreign:` reference accepts a MODEL class-string —
     * resolved to its table + primary key through the shared
     * ReferenceResolver at blueprint-build time (the same convention the
     * class-level #[ForeignKey] uses). The bare-table form references the
     * `id` convention.
     */
    public function testForeignAcceptsModelClass(): void
    {
        // Model-class reference — resolves to sync_users + its declared PK.
        $blueprint = (new Blueprint('sync_comments'))
            ->id()
            ->column(ColumnType::BigInt, 'authorId', foreign: SyncUser::class);

        $foreignKeys = $blueprint->getForeignKeys();

        self::assertSame(['authorId'], $foreignKeys[0]['columns']);
        self::assertSame(['sync_users', 'id'], $foreignKeys[0]['references']);

        // Bare-table reference — resolves to the `id` convention.
        $bare = (new Blueprint('sync_comments'))
            ->id()
            ->column(ColumnType::BigInt, 'authorId', foreign: 'sync_users');

        self::assertSame(['sync_users', 'id'], $bare->getForeignKeys()[0]['references']);
    }

    /**
     * A model reference to a COMPOSITE-PK model is rejected — there is no
     * single default column to target; the caller must use the explicit
     * `table.column` form.
     */
    public function testForeignModelWithCompositePkThrows(): void
    {
        $blueprint = new Blueprint('sync_comments');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('composite primary key');
        $blueprint->column(
            ColumnType::BigInt,
            'ownerId',
            foreign: SyncCompositePk::class,
        );
    }
}
