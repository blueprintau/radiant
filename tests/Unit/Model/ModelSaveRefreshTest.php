<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Model save/refresh edge cases beyond MetadataPipelineTest: insert-vs-
 * update branching, caller-assigned (non-auto-increment) PKs, dirty-
 * update targeting, stale delete via the model, and setAttribute()
 * boundary rejections.
 */
final class ModelSaveRefreshTest extends DatabaseTestCase
{
    /**
     * Create the mst_items / mst_guids fixture tables from BLUEPRINTS —
     * the assigned-PK fixture deliberately has no autoIncrement column.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            (new Blueprint('mst_items'))
                ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
                ->column(ColumnType::String, 'name', length: 64)
                ->column(ColumnType::Int, 'qty', nullable: true),
            (new Blueprint('mst_guids'))
                ->column(ColumnType::String, 'uuid', length: 36, primaryKey: true)
                ->column(ColumnType::String, 'label', length: 64),
        );
    }

    /**
     * Seed one item; return its id.
     *
     * @return int The generated id.
     */
    private function seedItem(): int
    {
        $item = new MstItem();
        $item->name = 'One';
        $item->save();

        return $item->id;
    }

    /**
     * A new model INSERTs; a loaded model UPDATEs — the branch is driven
     * by in-memory state (exists), not by a database probe.
     */
    public function testSaveInsertsThenUpdates(): void
    {
        $item = new MstItem();
        $item->name = 'One';
        self::assertTrue($item->save());
        self::assertSame(1, MstItem::all()->count(), 'the first save inserted');

        $item->name = 'One-edited';
        self::assertTrue($item->save());
        self::assertSame(1, MstItem::all()->count(), 'the second save updated, not inserted');

        $fresh = MstItem::find($item->id);
        self::assertNotNull($fresh);
        self::assertSame('One-edited', $fresh->name);
    }

    /**
     * An update only writes the DIRTY columns — a full-table read between
     * save and mutation keeps unchanged columns untouched (locked via a
     * raw row check, not hydration, so codec defaults can't mask it).
     */
    public function testUpdateWritesOnlyDirtyColumns(): void
    {
        $id = $this->seedItem();

        $item = MstItem::find($id);
        self::assertNotNull($item);
        $item->name = 'Renamed';
        $item->save();

        // The qty column never entered an UPDATE — its stored value is
        // unchanged (NULL here).
        $raw = $this->connection->table('mst_items')->where('id', '=', $id)->first();
        self::assertNotNull($raw);
        self::assertSame('Renamed', $raw->name);
        self::assertNull($raw->qty);
    }

    /**
     * A caller-assigned (non-auto-increment) PK round-trips: save() INSERTs
     * with the caller's key, and updates target that key.
     */
    public function testCallerAssignedPrimaryKeyRoundTrips(): void
    {
        $guid = new MstGuid();
        $guid->uuid = 'abc-123';
        $guid->label = 'First';
        self::assertTrue($guid->save());
        self::assertSame('abc-123', $guid->uuid, 'the caller-assigned key survives');

        $found = MstGuid::find('abc-123');
        self::assertNotNull($found);
        self::assertSame('First', $found->label);

        // Update via the assigned key.
        $found->label = 'Second';
        self::assertTrue($found->save());
        self::assertSame(1, MstGuid::all()->count(), 'still one row — the update did not duplicate');

        $reread = MstGuid::find('abc-123');
        self::assertNotNull($reread);
        self::assertSame('Second', $reread->label);
    }

    /**
     * delete() on a model whose row was hard-deleted elsewhere returns
     * false and clears exists — the model-level stale contract (pairs
     * with the SoftDeletesTest lock on the trait's delete()).
     */
    public function testDeleteOnStaleInstanceReturnsFalse(): void
    {
        $id = $this->seedItem();

        $item = MstItem::find($id);
        self::assertNotNull($item);

        $this->connection->table('mst_items')->where('id', '=', $id)->delete();

        self::assertFalse($item->delete());
        self::assertCount(0, MstItem::all());
    }

    /**
     * A second save() on a DELETED instance re-INSERTs (exists was cleared
     * by the failed delete) — the row comes back with a NEW generated key.
     */
    public function testSaveAfterDeleteReinserts(): void
    {
        $id = $this->seedItem();

        $item = MstItem::find($id);
        self::assertNotNull($item);
        self::assertTrue($item->delete());

        self::assertTrue($item->save(), 'exists is false, so save() takes the INSERT branch');
        self::assertCount(1, MstItem::all());
        self::assertSame($id, $item->id, 'the caller-assigned id carries over on the re-insert');
    }

    /**
     * setAttribute() rejects a typed-property-backed column — writing it
     * would silently diverge from what the property reads.
     */
    public function testSetAttributeRejectsTypedPropertyColumn(): void
    {
        $item = new MstItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('backed by a typed property');
        $item->setAttribute('name', 'nope');
    }

    /**
     * setAttribute() rejects an unknown column.
     */
    public function testSetAttributeRejectsUnknownColumn(): void
    {
        $item = new MstItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column');
        $item->setAttribute('bogus', 'nope');
    }

    /**
     * THE NULL-KEY WRITE GUARD: update() on a model whose PK was never
     * hydrated throws LogicException instead of compiling
     * `WHERE pk IS NULL` and reporting success for a no-op.
     */
    public function testUpdateWithoutResolvedKeyThrows(): void
    {
        $item = new MstItem();
        $item->name = 'Never saved';

        // Force the UPDATE branch without a key: exists is flipped behind
        // the guard's back, mirroring a caller-owned select that skipped
        // hydration of the key.
        $ref = new \ReflectionProperty(Model::class, 'exists');
        $ref->setValue($item, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot target its row');

        $item->name = 'Edited';
        $item->save();
    }

    /**
     * Same guard for delete(): a keyless model throws instead of issuing
     * `WHERE pk IS NULL` (which on SQLite can match OTHER rows).
     */
    public function testDeleteWithoutResolvedKeyThrows(): void
    {
        $item = new MstItem();
        $item->name = 'Never saved';

        $ref = new \ReflectionProperty(Model::class, 'exists');
        $ref->setValue($item, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot target its row');

        $item->delete();
    }

    /**
     * The guard is write-only: getKeyForRefresh() stays lenient so
     * Collection::find()/fresh() treat an unresolved key as "no match".
     */
    public function testKeyForRefreshStaysLenientOnUnsavedModel(): void
    {
        $item = new MstItem();
        $item->name = 'Never saved';

        self::assertNull($item->getKeyForRefresh());
    }
}

/**
 * Fixture: item with an auto-increment key.
 */
#[\BlueprintAU\Radiant\Attributes\Table(name: 'mst_items')]
class MstItem extends Model
{
    /**
     * The item's id.
     *
     * @var int
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The item name.
     *
     * @var string
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The stocked quantity (nullable).
     *
     * @var int|null
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::Int, nullable: true)]
    public ?int $qty;
}

/**
 * Fixture: entity with a caller-assigned string PK.
 */
#[\BlueprintAU\Radiant\Attributes\Table(name: 'mst_guids')]
class MstGuid extends Model
{
    /**
     * The caller-assigned UUID key (NOT auto-increment).
     *
     * @var string
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::String, length: 36, primaryKey: true)]
    public string $uuid;

    /**
     * The label.
     *
     * @var string
     */
    #[\BlueprintAU\Radiant\Attributes\Column(type: ColumnType::String, length: 64)]
    public string $label;
}
