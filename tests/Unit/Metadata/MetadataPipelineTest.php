<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\ModelIntrospection;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\Admin;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\DefaultedModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\RenamedColumnModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\SoftDeletingPost;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\User;

/**
 * End-to-end metadata pipeline tests: metadata → Blueprint → DDL → live
 * SQLite round-trips (save / find / update / soft delete).
 *
 * The users table is built by hand — the User fixture's roleId column
 * declares a foreign key to `roles.id`, and this suite never creates a
 * roles table (SQLite enforces FKs), so the metadata-driven DDL would
 * fail. The point is proving the metadata drives real queries.
 */
final class MetadataPipelineTest extends DatabaseTestCase
{
    /**
     * Create the users table (hand-built — see the class docblock) and
     * the soft_deleting_posts table from the model's attributes.
     */
    protected function setUpDatabase(): void
    {
        $users = (new Blueprint('users'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'email', length: 255, unique: true)
            ->column(ColumnType::String, 'password', length: 255)
            ->column(ColumnType::DateTime, 'emailVerifiedAt', nullable: true)
            ->column(ColumnType::BigInt, 'roleId', index: true)
            ->column(ColumnType::Json, 'meta', nullable: true);

        $this->createTables($users, SoftDeletingPost::class);
    }

    /**
     * save() inserts, hydrates through the column casts, and the generated
     * id lands back on the model.
     */
    public function testInsertAndHydrate(): void
    {
        $user = new User();
        $user->email = 'alice@example.com';
        $user->password = 'secret';
        $user->roleId = 7;
        $user->meta = ['theme' => 'dark'];
        $user->save();

        self::assertGreaterThan(0, $user->id);

        $found = User::find($user->id);

        self::assertNotNull($found);
        self::assertSame('alice@example.com', $found->email);
        self::assertSame('secret', $found->password);
        self::assertSame(7, $found->roleId);
        self::assertSame(['theme' => 'dark'], $found->meta);
    }

    /**
     * save() on an existing model updates only the dirty columns.
     */
    public function testUpdateWritesDirtyColumns(): void
    {
        $user = new User();
        $user->email = 'bob@example.com';
        $user->password = 'old';
        $user->roleId = 1;
        $user->save();

        $user->password = 'new';
        $user->save();

        $raw = $this->connection->table('users')->where('id', '=', $user->id)->first();
        self::assertNotNull($raw);
        self::assertSame('new', $raw->password);
    }

    /**
     * The soft-delete scope is auto-applied: delete() stamps deleted_at,
     * queries exclude the row, and withTrashed()/onlyTrashed() see it.
     */
    public function testSoftDeleteScopeLifecycle(): void
    {
        $post = new SoftDeletingPost();
        $post->title = 'Hello';
        $post->save();

        $post->delete();

        $raw = $this->connection->table('soft_deleting_posts')->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->deleted_at);

        // The auto-applied scope excludes the soft-deleted row.
        self::assertNull(SoftDeletingPost::find($post->id));

        // withTrashed() removes the scope; onlyTrashed() inverts it.
        $withTrashed = SoftDeletingPost::newQuery()->withTrashed()->where('id', '=', $post->id)->first();
        self::assertNotNull($withTrashed);
        self::assertInstanceOf(SoftDeletingPost::class, $withTrashed);
        self::assertTrue($withTrashed->trashed());

        $onlyTrashed = SoftDeletingPost::newQuery()->onlyTrashed()->where('id', '=', $post->id)->first();
        self::assertNotNull($onlyTrashed);
    }

    /**
     * restore() clears the soft-delete stamp; the row becomes visible again.
     */
    public function testRestoreClearsSoftDelete(): void
    {
        $post = new SoftDeletingPost();
        $post->title = 'Restorable';
        $post->save();
        $post->delete();

        $trashed = SoftDeletingPost::newQuery()->withTrashed()->find($post->id);
        self::assertNotNull($trashed);
        self::assertInstanceOf(SoftDeletingPost::class, $trashed);
        $trashed->restore();

        $raw = $this->connection->table('soft_deleting_posts')->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNull($raw->deleted_at);
        self::assertNotNull(SoftDeletingPost::find($post->id));
    }

    /**
     * Column validation fails fast on unknown columns.
     */
    public function testUnknownColumnValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column [nonexistent] on model');
        User::where('nonexistent', '=', 1);
    }

    /**
     * setAttribute() is the synthetic-column store ONLY: a column backed by
     * a typed property must be written through the property itself. Writing
     * it via setAttribute() would silently diverge from what the property
     * (and every typed read) sees — so it fails fast.
     */
    public function testSetAttributeRejectsTypedPropertyColumns(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('backed by a typed property; write the property directly');
        $user->setAttribute('email', 'via-set-attribute@example.com');
    }

    /**
     * setAttribute() rejects unknown columns too — the store is not a
     * free-form bag.
     */
    public function testSetAttributeRejectsUnknownColumns(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column [nonexistent]');
        $user->setAttribute('nonexistent', 'x');
    }

    /**
     * The synthetic-column path still works: SoftDeletes writes the delete
     * timestamp through setAttribute() and reads it back via attribute().
     */
    public function testSetAttributeRoundTripsSyntheticColumns(): void
    {
        $post = new SoftDeletingPost();
        $post->title = 'synthetic';
        $post->save();

        $stamp = new \Carbon\Carbon('2026-09-08 10:00:00', 'UTC');
        $post->setAttribute('deleted_at', $stamp);

        self::assertSame($stamp, $post->attribute('deleted_at'));
    }

    /**
     * The Carbon cast round-trips through the codec on a live connection.
     */
    public function testCarbonCastRoundTrip(): void
    {
        $user = new User();
        $user->email = 'carol@example.com';
        $user->password = 'x';
        $user->roleId = 2;
        $user->emailVerifiedAt = new \Carbon\Carbon('2026-09-06 12:00:00', 'UTC');
        $user->save();

        $found = User::find($user->id);

        self::assertNotNull($found);
        self::assertInstanceOf(\Carbon\Carbon::class, $found->emailVerifiedAt);
        self::assertSame('2026-09-06 12:00:00', $found->emailVerifiedAt->format('Y-m-d H:i:s'));
    }

    /**
     * A freshly hydrated model is NOT spuriously dirty.
     *
     * This is the regression lock for the `$original` value-space design:
     * the snapshot must live in the ENCODED space (Carbon objects for
     * datetime columns, JSON strings for json columns) so `getDirty()`'s
     * `!=` compares like with like. Seeding `$original` with raw row bytes
     * instead would compare `'2026-09-06 12:00:00' != Carbon` — always
     * true — making every datetime column permanently dirty and every
     * `save()` emit a spurious UPDATE.
     */
    public function testHydratedModelIsNotSpuriouslyDirty(): void
    {
        $user = new User();
        $user->email = 'clean@example.com';
        $user->password = 'x';
        $user->roleId = 3;
        $user->emailVerifiedAt = new \Carbon\Carbon('2026-09-06 08:00:00', 'UTC');
        $user->meta = ['theme' => 'dark'];
        $user->save();

        $probe = Admin::find($user->id);

        self::assertNotNull($probe);
        self::assertInstanceOf(Admin::class, $probe);
        self::assertSame([], ModelIntrospection::dirtyOf($probe));
    }

    /**
     * Dirty tracking names exactly the changed columns with their encoded
     * values — and nothing else.
     *
     * A partial new model: only the properties actually set are dirty
     * (uninitialized typed properties are skipped — a partial model writes
     * only what it holds), and values arrive in the ENCODED space (array →
     * JSON string) ready to bind.
     */
    public function testDirtyColumnsNameExactlyTheChangedOnes(): void
    {
        $user = new User();
        $user->email = 'partial@example.com';
        $user->meta = ['theme' => 'dark'];

        $dirty = ModelIntrospection::dirtyOf($user);

        self::assertSame(['email' => 'partial@example.com', 'meta' => '{"theme":"dark"}'], $dirty);
    }

    /**
     * Dirty tracking after hydration + mutation: the changed column is
     * reported (old value vs new compared in the encoded space), unchanged
     * columns are NOT, and a same-value re-assignment stays clean.
     */
    public function testDirtyAfterMutationTracksOnlyChangedColumns(): void
    {
        $user = new User();
        $user->email = 'track@example.com';
        $user->password = 'old';
        $user->roleId = 5;
        $user->meta = ['a' => 1];
        $user->save();

        $probe = Admin::find($user->id);
        self::assertNotNull($probe);
        self::assertInstanceOf(Admin::class, $probe);
        self::assertSame([], ModelIntrospection::dirtyOf($probe)); // baseline clean

        $probe->password = 'new';

        self::assertSame(['password' => 'new'], ModelIntrospection::dirtyOf($probe));

        // Same-value re-assignment: NOT dirty (the whole point of the
        // encoded-space comparison — no spurious UPDATE).
        $probe->password = 'new';
        // phpstan 2.2.16's alreadyNarrowedType check wrongly treats a
        // concrete shape vs array<string, mixed> as always-identical.
        /** @phpstan-ignore staticMethod.alreadyNarrowedType (the shape-vs-generic-array comparison is the assertion's subject) */
        self::assertSame(['password' => 'new'], ModelIntrospection::dirtyOf($probe));
    }

    /**
     * A mutated datetime column IS dirty (the Carbon object vs the loaded
     * Carbon compares by value — equal instants stay clean, a changed
     * instant reports), and after save() the model is clean again.
     */
    public function testDatetimeMutationIsDirtyThenCleansAfterSave(): void
    {
        $user = new User();
        $user->email = 'dt@example.com';
        $user->password = 'x';
        $user->roleId = 6;
        $user->emailVerifiedAt = new \Carbon\Carbon('2026-09-06 08:00:00', 'UTC');
        $user->save();

        $probe = Admin::find($user->id);
        self::assertNotNull($probe);
        self::assertInstanceOf(Admin::class, $probe);

        // A DIFFERENT instant → dirty (Carbon compared BY VALUE — two
        // Carbon objects for the same instant are equal; assertSame would
        // fail on object identity, which is not the semantic we want).
        $probe->emailVerifiedAt = new \Carbon\Carbon('2027-01-01 00:00:00', 'UTC');
        self::assertEquals(
            ['emailVerifiedAt' => new \Carbon\Carbon('2027-01-01 00:00:00', 'UTC')],
            ModelIntrospection::dirtyOf($probe),
        );

        // Save → the dirty value is persisted and the snapshot re-syncs.
        $probe->save();
        self::assertSame([], ModelIntrospection::dirtyOf($probe));

        // The new instant survived the round-trip.
        $reloaded = User::find($user->id);
        self::assertNotNull($reloaded);
        self::assertSame('2027-01-01 00:00:00', $reloaded->emailVerifiedAt?->format('Y-m-d H:i:s'));
    }

    /**
     * The builder declares the insert id column, so insertGetId() returns
     * the generated id on the RETURNING path.
     */
    public function testInsertIdColumnWiring(): void
    {
        $query = User::newQuery();

        self::assertInstanceOf(ModelQueryBuilder::class, $query);
        self::assertSame('id', $query->getInsertIdColumn());

        $id = $query->insert([
            'email' => 'dave@example.com',
            'password' => 'x',
            'roleId' => 1,
        ]);

        self::assertGreaterThan(0, (int) $id);
    }

    /**
     * SQLite does not support dropping columns — the dialect gate fails
     * fast instead of silently ignoring the operation.
     */
    public function testAlterDropsColumn(): void
    {
        $blueprint = (new Blueprint('users'))->dropColumn('meta');

        $this->expectException(\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support dropping columns');
        $this->connection->alter(SchemaOperation::DropColumn, $blueprint);
    }

    // ---- Renamed columns: columnName ≠ propertyName end to end ----

    /**
     * A model whose DB column names differ from the property names works
     * end to end: save() writes the DB names, find() hydrates the property
     * names, and the round-trip is lossless.
     */
    public function testRenamedColumnsSaveAndHydrate(): void
    {
        $this->connection->create(Blueprint::fromMetadata(RenamedColumnModel::class));

        $model = new RenamedColumnModel();
        $model->status = 'active';
        $model->when = new \Carbon\Carbon('2026-09-06 10:00:00', 'UTC');
        $model->save();

        // The row landed under the DB column names, not the property names.
        $raw = $this->connection->table('renamed_columns')->where('pk', '=', $model->id)->first();
        self::assertNotNull($raw);
        self::assertSame('active', $raw->state);
        self::assertSame('2026-09-06 10:00:00', $raw->occurred_at);

        // Hydration maps the DB names back onto the properties.
        $found = RenamedColumnModel::find($model->id);
        self::assertNotNull($found);
        self::assertSame('active', $found->status);
        self::assertInstanceOf(\Carbon\Carbon::class, $found->when);
        self::assertSame('2026-09-06 10:00:00', $found->when->format('Y-m-d H:i:s'));
    }

    /**
     * Dirty tracking stays column-keyed with renamed columns: a mutated
     * renamed property reports its DB column name, and save() targets the
     * right row via the (renamed) PK.
     */
    public function testRenamedColumnsDirtyTracking(): void
    {
        $this->connection->create(Blueprint::fromMetadata(RenamedColumnModel::class));

        $model = new RenamedColumnModel();
        $model->status = 'draft';
        $model->save();

        $probe = RenamedColumnModel::find($model->id);
        self::assertNotNull($probe);
        self::assertInstanceOf(RenamedColumnModel::class, $probe);
        self::assertSame([], ModelIntrospection::dirtyOf($probe)); // hydrated = clean

        $probe->status = 'published';

        // Dirty is keyed by the DB column name (`state`), not `status`.
        self::assertSame(['state' => 'published'], ModelIntrospection::dirtyOf($probe));

        $probe->save();

        // save() retargeted the row via the renamed PK (`pk`) and wrote the
        // renamed column (`state`).
        $raw = $this->connection->table('renamed_columns')->where('pk', '=', $model->id)->first();
        self::assertNotNull($raw);
        self::assertSame('published', $raw->state);
        self::assertSame([], ModelIntrospection::dirtyOf($probe));
    }

    /**
     * Uninitialized properties with declared column defaults are
     * materialized onto the model after save() — the in-memory model
     * matches the row the DB default produced, without a re-fetch.
     *
     * A column WITHOUT a declared default stays uninitialized, and the
     * materialized model is clean (materialization is not dirty state).
     */
    public function testInsertMaterializesColumnDefaults(): void
    {
        $this->connection->create(
            (new Blueprint('defaulted_models'))
                ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
                ->column(ColumnType::Int, 'hits', default: 0)
                ->column(ColumnType::String, 'author', length: 32, nullable: true, default: 'anon')
                ->column(ColumnType::String, 'note', length: 255, nullable: true),
        );

        // EVERY column omitted at insert — the empty-row compile path
        // (`INSERT INTO ... DEFAULT VALUES`) fires, applying all defaults.
        $model = new DefaultedModel();
        $model->save();

        self::assertTrue(ModelIntrospection::existsOf($model));
        self::assertSame(0, $model->hits);
        self::assertSame('anon', $model->author);

        // `note` was never set and has no declared default — the property
        // stays uninitialized after save().
        self::assertFalse(ModelIntrospection::propertyInitialized($model, 'note'));

        // The row agrees with the model.
        $raw = $this->connection->table('defaulted_models')->first();
        self::assertNotNull($raw);
        self::assertSame(0, $raw->hits);
        self::assertSame('anon', $raw->author);

        // Materialized values are the original snapshot, not dirty state —
        // a second save() emits no spurious UPDATE.
        self::assertSame([], ModelIntrospection::dirtyOf($model));
        $model->save();
    }
}
