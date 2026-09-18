<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Dedicated coverage for the {@see SoftDeletes} trait beyond the lifecycle
 * in MetadataPipelineTest: the stale-row contract (delete()/restore()
 * report honestly when the row is gone), re-delete
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
        $this->createTables(SdPost::class, SdRenamedPost::class);
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
     * delete() flips trashed(), hides the row from the default scope, and
     * keeps it reachable via withTrashed().
     */
    public function testDeleteLifecycle(): void
    {
        $post = $this->seedPost();

        self::assertFalse($post->trashed());
        self::assertTrue($post->delete());
        self::assertTrue($post->trashed(), 'trashed() reads the in-memory state after delete');

        self::assertCount(0, SdPost::all(), 'the default scope excludes soft-deleted rows');

        $trashed = SdPost::newQuery()->onlyTrashed()->get();
        self::assertCount(1, $trashed);

        $all = SdPost::newQuery()->withTrashed()->get();
        self::assertCount(1, $all);
    }

    /**
     * Re-deleting an already-soft-deleted row returns true — the UPDATE
     * matches the row directly (whereKey bypasses the scope) and refreshing
     * the timestamp is a legitimate soft delete.
     */
    public function testRedeleteReturnsTrue(): void
    {
        $post = $this->seedPost();
        $post->delete();

        self::assertTrue($post->delete(), 're-delete targets the row directly and succeeds');
    }

    /**
     * restore() clears the timestamp and returns the row to the default
     * scope; restore() on a live (never-deleted) row is a harmless no-op
     * UPDATE that still matches the row — true.
     */
    public function testRestoreLifecycle(): void
    {
        $post = $this->seedPost();
        $post->delete();

        self::assertTrue($post->restore());
        self::assertFalse($post->trashed());
        self::assertCount(1, SdPost::all(), 'the restored row is visible again');
    }

    /**
     * THE STALE-ROW CONTRACT (regression lock): delete() on a
     * model whose row was hard-deleted by another connection must return
     * FALSE and clear exists — the caller's compensation logic must not
     * fire for a row that is not there. (Pre-fix: the UPDATE matched 0
     * rows but the trait reported success.)
     */
    public function testDeleteOnStaleInstanceReturnsFalse(): void
    {
        $post = $this->seedPost();

        // Hard-delete the row behind the instance's back.
        $this->connection->table('sd_posts')->where('id', '=', $post->id)->delete();

        self::assertFalse($post->delete(), 'a stale delete must report failure, not success');
        self::assertCount(0, SdPost::newQuery()->withTrashed()->get());
    }

    /**
     * Same contract for restore(): the row was soft-deleted here, then
     * hard-deleted elsewhere — restore() matches 0 rows, returns false,
     * and clears exists.
     */
    public function testRestoreOnStaleInstanceReturnsFalse(): void
    {
        $post = $this->seedPost();
        $post->delete();

        // Hard-delete the soft-deleted row behind the instance's back.
        $this->connection->table('sd_posts')->where('id', '=', $post->id)->delete();

        self::assertFalse($post->restore(), 'a stale restore must report failure, not success');
    }

    /**
     * forceDelete() removes the row entirely — including from withTrashed().
     */
    public function testForceDelete(): void
    {
        $post = $this->seedPost();
        $post->delete();
        self::assertCount(1, SdPost::newQuery()->onlyTrashed()->get());

        self::assertTrue($post->forceDelete());

        self::assertCount(0, SdPost::newQuery()->withTrashed()->get());
    }

    /**
     * forceDelete() on a soft-deleted row deleted elsewhere reports the
     * stale instance (exists cleared, false) — the base performDelete()
     * contract this trait rides on.
     */
    public function testForceDeleteOnStaleInstanceReturnsFalse(): void
    {
        $post = $this->seedPost();
        $post->delete();

        $this->connection->table('sd_posts')->where('id', '=', $post->id)->delete();

        self::assertFalse($post->forceDelete());
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
        self::assertNotNull($raw[0]->renamed_at);
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

                self::assertTrue($post->restore());
                self::assertCount(1, SdPost::all());
            });
        } finally {
            @unlink($path);
            @unlink($path . '.lock');
            @unlink($path . '.radiant-tmp');
        }
    }
}

/**
 * Fixture: soft-deletable post.
 */
#[\BlueprintAU\Radiant\Attributes\Table(name: 'sd_posts')]
class SdPost extends Model
{
    use SoftDeletes;

    /**
     * The post's id.
     *
     * @var int
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::String, length: 255)]
    public string $title;
}

/**
 * Fixture: soft-deletable post with a RENAMED delete column.
 */
#[\BlueprintAU\Radiant\Attributes\Table(name: 'sd_renamed_posts')]
class SdRenamedPost extends Model
{
    use SoftDeletes;

    /**
     * The renamed soft-delete column.
     *
     * @return string The column name.
     */
    public static function deletedAtColumn(): string
    {
        return 'renamed_at';
    }

    /**
     * The post's id.
     *
     * @var int
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The renamed soft-delete timestamp (user-declared, name matches).
     *
     * @var \Carbon\Carbon|null
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::DateTime, name: 'renamed_at', nullable: true)]
    public ?\Carbon\Carbon $renamedAt;
}
