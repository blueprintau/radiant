<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpShipment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpTrackedShipment;

/**
 * Live-SQLite E2E for composite-key paths: composite-PK find/save/delete,
 * composite relations (HasMany/HasOne/BelongsTo, lazy + eager), the
 * composite `whereKey()` guards, Collection key handling, and the
 * composite-PK model-class #[ForeignKey] resolution.
 */
class CompositeKeysE2ETest extends DatabaseTestCase
{
    /**
     * Create the composite-key fixture tables from their model metadata.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(CmpRegion::class, CmpShipment::class, CmpTrackedShipment::class);
    }

    /**
     * Seed two regions and two shipments (one per region).
     *
     * @return array{alpha: CmpRegion, beta: CmpRegion}
     */
    private function seed(): array
    {
        $alpha = new CmpRegion();
        $alpha->id = 1;
        $alpha->country = 'US';
        $alpha->name = 'Alpha';
        $alpha->save();

        $beta = new CmpRegion();
        $beta->id = 1;
        $beta->country = 'DE';
        $beta->name = 'Beta';
        $beta->save();

        $first = new CmpShipment();
        $first->regionId = 1;
        $first->country = 'US';
        $first->title = 'First';
        $first->save();

        $second = new CmpShipment();
        $second->regionId = 1;
        $second->country = 'DE';
        $second->title = 'Second';
        $second->save();

        return ['alpha' => $alpha, 'beta' => $beta];
    }

    /**
     * find() with a composite map: the tuple identifies the row — (1, US)
     * and (1, DE) are DIFFERENT rows sharing the id.
     */
    public function testCompositeFind(): void
    {
        $this->seed();

        $us = CmpRegion::find(['id' => 1, 'country' => 'US']);
        $de = CmpRegion::find(['id' => 1, 'country' => 'DE']);

        self::assertNotNull($us);
        self::assertNotNull($de);
        self::assertSame('Alpha', $us->name);
        self::assertSame('Beta', $de->name);
        self::assertNull(CmpRegion::find(['id' => 1, 'country' => 'FR']));
    }

    /**
     * Composite find() rejects a key with a non-PK column — a typo must
     * fail loudly, not silently match nothing.
     */
    public function testCompositeFindRejectsNonPkColumn(): void
    {
        $this->seed();

        Expectation::throwsWithMessage(
            fn () => $this->findUntyped(CmpRegion::class, ['id' => 1, 'region' => 'US']),
            \InvalidArgumentException::class,
            'is not a primary key of model',
        );
    }

    /**
     * Call find() with an untyped key — the boundary under test.
     *
     * @param class-string<Model> $class The model class.
     * @param mixed $id The key as an untyped caller supplied it.
     * @return Model|null
     */
    private function findUntyped(string $class, mixed $id): ?Model
    {
        return $class::find($id);
    }

    /**
     * A composite-PK model round-trips save(): insert assigns no generated
     * id (caller-provided tuple), update targets the full tuple.
     */
    public function testCompositeSaveUpdateAndDelete(): void
    {
        $this->seed();

        $region = CmpRegion::find(['id' => 1, 'country' => 'DE']);
        self::assertNotNull($region);

        $region->name = 'Beta Updated';
        $region->save();

        $refetched = CmpRegion::find(['id' => 1, 'country' => 'DE']);
        self::assertNotNull($refetched);
        self::assertSame('Beta Updated', $refetched->name);

        // The sibling (1, US) must be untouched — the update matched the
        // FULL tuple, not the id alone.
        $sibling = CmpRegion::find(['id' => 1, 'country' => 'US']);
        self::assertNotNull($sibling);
        self::assertSame('Alpha', $sibling->name);

        $region->delete();
        self::assertNull(CmpRegion::find(['id' => 1, 'country' => 'DE']));
        self::assertNotNull(CmpRegion::find(['id' => 1, 'country' => 'US']));
    }

    /**
     * getKeyForRefresh() returns the full composite map.
     */
    public function testCompositeKeyForRefresh(): void
    {
        $this->seed();

        $region = CmpRegion::find(['id' => 1, 'country' => 'US']);
        self::assertNotNull($region);
        self::assertSame(['id' => 1, 'country' => 'US'], $region->getKeyForRefresh());
    }

    /**
     * Lazy composite HasMany: both FK columns constrain the query.
     */
    public function testLazyCompositeHasMany(): void
    {
        ['alpha' => $alpha, 'beta' => $beta] = $this->seed();

        self::assertCount(1, $alpha->shipments()->get());
        self::assertNotNull($alpha->shipments()->get()[0]);
        self::assertSame('First', $alpha->shipments()->get()[0]->title);
        self::assertCount(1, $beta->shipments()->get());
        self::assertNotNull($beta->shipments()->get()[0]);
        self::assertSame('Second', $beta->shipments()->get()[0]->title);
    }

    /**
     * The lazy composite constraint lands inside a whereNested GROUP — the
     * tuple is ONE constraint unit. A caller's later `->orWhere(...)`
     * must OR at the constraint's EDGES (tuple OR x), never against the
     * tuple's PARTS ((fk1 AND fk2) OR x — which would match the wrong
     * rows). Regression: the tuple was applied flat, so `->orWhere('title',
     * 'Second')` returned BOTH shipments (the (1, US) tuple's fk parts
     * ORed against the title) instead of only the second one.
     */
    public function testLazyConstraintComposesOrCorrectly(): void
    {
        $this->seed();

        $alpha = CmpRegion::find(['id' => 1, 'country' => 'US']);
        self::assertNotNull($alpha);

        // Flat tuple: fk1 = 1 AND country = 'US' OR title = 'Second'
        // → the (1, US) match ORs the (1, DE) row in — 2 rows.
        // Grouped tuple: (fk1 = 1 AND country = 'US') OR title = 'Second'
        // → exactly the (1, US) row plus the Second row — 2 rows but the
        // (1, DE) 'First' row must NOT appear; and a title filter that
        // matches nothing must leave ONLY the tuple match.
        $results = $alpha->shipments()->orWhere('title', '=', 'Second')->get();

        self::assertCount(2, $results);

        $titles = array_map(static fn (Model $shipment) => $shipment->attribute('title'), $results->all());
        sort($titles);
        self::assertSame(['First', 'Second'], $titles);

        // The decisive case: an OR filter matching a row OUTSIDE the tuple.
        // 'Second' belongs to (1, DE) — its presence proves the OR hit the
        // constraint's edge; if the tuple had leaked flat, the (1, DE)
        // 'First' row would ALSO appear (its fk1 part matches).
        self::assertNotContains('Second', $alpha->shipments()->get()->map(
            static fn (Model $shipment) => $shipment->attribute('title'),
        )->toArray(), 'the tuple tuple alone must not match (1, DE) rows');
    }

    /**
     * The grouped constraint's SQL shape: the tuple's parts AND inside
     * parens; an appended OR prefixes the parens, not the parts.
     */
    public function testLazyConstraintSqlShape(): void
    {
        $this->seed();

        $alpha = CmpRegion::find(['id' => 1, 'country' => 'US']);
        self::assertNotNull($alpha);

        $sql = (new \BlueprintAU\Radiant\Database\Grammars\SqliteGrammar())
            ->compileSelect($alpha->shipments()->orWhere('title', '=', 'Second')->getQuery());

        self::assertStringContainsString(
            "WHERE (\"region_id\" = ? AND \"country\" = ?) OR \"title\" = ?",
            $sql,
        );
    }

    /**
     * Lazy composite HasOne: the first row per full tuple.
     */
    public function testLazyCompositeHasOne(): void
    {
        $this->seed();

        $alpha = CmpRegion::find(['id' => 1, 'country' => 'US']);
        self::assertNotNull($alpha);

        $shipment = $alpha->primaryShipment()->get();
        self::assertCount(1, $shipment);
        self::assertNotNull($shipment[0]);
        self::assertSame('First', $shipment[0]->title);
    }

    /**
     * Lazy composite BelongsTo: the child resolves its owner by tuple.
     */
    public function testLazyCompositeBelongsTo(): void
    {
        $this->seed();

        $shipment = CmpShipment::where('title', '=', 'Second')->first();
        self::assertNotNull($shipment);

        $region = $shipment->region()->get();
        self::assertCount(1, $region);
        self::assertNotNull($region[0]);
        self::assertSame('Beta', $region[0]->name);
    }

    /**
     * Eager composite HasMany via with(): one OR-groups query, children
     * stitched by full tuple.
     */
    public function testEagerCompositeHasMany(): void
    {
        $this->seed();

        $regions = CmpRegion::with('shipments')->orderBy('country')->get();

        self::assertCount(2, $regions);
        self::assertNotNull($regions[0]);
        self::assertNotNull($regions[1]);
        // The typed relation method narrows statically — no local
        // instanceof dance: shipments()->get() is
        // Collection<CmpShipment>, so ->first() is CmpShipment|null
        // and property access type-checks after the null assert.
        $deShipments = $regions[0]->shipments()->get();
        $usShipments = $regions[1]->shipments()->get();
        self::assertInstanceOf(Collection::class, $deShipments);
        self::assertInstanceOf(Collection::class, $usShipments);
        $deShipment = $deShipments->first();
        $usShipment = $usShipments->first();
        self::assertNotNull($deShipment);
        self::assertNotNull($usShipment);
        self::assertSame('First', $usShipment->title);
        self::assertSame('Second', $deShipment->title);
    }

    /**
     * Eager composite BelongsTo via with(): the FK tuple lives on the
     * parent; non-overlapping ids prove the tuple (not the id) matches.
     */
    public function testEagerCompositeBelongsTo(): void
    {
        $this->seed();

        $shipments = CmpShipment::with('region')->orderBy('country')->get();

        self::assertCount(2, $shipments);
        self::assertNotNull($shipments[0]);
        self::assertNotNull($shipments[1]);
        $deRegion = $shipments[0]->region()->get()->first();
        $usRegion = $shipments[1]->region()->get()->first();
        self::assertInstanceOf(CmpRegion::class, $deRegion);
        self::assertInstanceOf(CmpRegion::class, $usRegion);
        self::assertSame('Beta', $deRegion->name);
        self::assertSame('Alpha', $usRegion->name);
    }

    /**
     * Composite eager loading with a NULL FK component: the parent
     * contributes no key, the relation loads empty — no crash.
     */
    public function testEagerCompositeSkipsNullComponents(): void
    {
        $this->seed();

        $orphan = new CmpShipment();
        $orphan->id = 99;
        $orphan->country = 'US';
        $orphan->title = 'Orphan';
        $orphan->save();

        $shipments = CmpShipment::with('region')->orderBy('id')->get();
        self::assertCount(3, $shipments);
        self::assertNotNull($shipments[2]);
        self::assertCount(0, $shipments[2]->region()->get());
    }

    /**
     * A NULL-able FK is a REAL SQL-semantic case, not a crash: a tuple
     * match with an IS NULL component matches NOTHING (SQL `NULL = x` is
     * never true), so a parent with a NULL part resolves an empty relation
     * — both lazy and eager — and never a partial-tuple match (which would
     * drag in EVERY row sharing the non-NULL part).
     */
    public function testNullableFkPartMatchesNothing(): void
    {
        $this->seed();

        // A shipment with a NULL first FK part but a non-NULL second part —
        // a partial-tuple match would return every US shipment (the
        // non-NULL part shared), the classic OR-composition bug.
        $partial = new CmpShipment();
        $partial->id = 98;
        $partial->country = 'US';
        $partial->title = 'Partial';
        $partial->save();

        $shipment = CmpShipment::find(98);
        self::assertNotNull($shipment);

        // Lazy BelongsTo: the NULL part resolves empty — never partial.
        $region = $shipment->region()->get();
        self::assertCount(0, $region);

        // Eager BelongsTo: same empty result, no crash.
        $eager = CmpShipment::with('region')->orderBy('id')->get();
        self::assertNotNull($eager[2]);
        self::assertCount(0, $eager[2]->region()->get());

        // And the OTHER direction: shipments() under a region with a NULL
        // part in its tuple — the region cannot own a partial match.
        $orphan = new CmpRegion();
        $orphan->id = 99;
        $orphan->country = '';
        $orphan->name = 'Ghost';
        $orphan->save();

        self::assertCount(0, $orphan->shipments()->get());
    }

    /**
     * Collection::find() matches composite keys by shape — order
     * independently, null components strictly.
     */
    public function testCollectionCompositeFind(): void
    {
        $this->seed();

        $all = CmpRegion::orderBy('country')->get();

        $de = $all->find(['country' => 'DE', 'id' => 1]);
        self::assertInstanceOf(CmpRegion::class, $de);
        self::assertSame('Beta', $de->name);

        // Null never matches 0 / '' — a strict pair-wise comparison.
        self::assertNull($all->find(['id' => 1, 'country' => null]));

        // A different shape (fewer columns) is not a match.
        self::assertNull($all->find(['id' => 1]));

        // Scalar keys still round-trip cross-type.
        $shipments = CmpShipment::all();
        $any = $shipments->first();
        self::assertNotNull($any);
        $found = $shipments->find((string) $any->id);
        self::assertInstanceOf(CmpShipment::class, $found);
        self::assertSame($any->id, $found->id);
    }

    /**
     * modelKeys() returns the composite maps.
     */
    public function testCollectionModelKeysComposite(): void
    {
        $this->seed();

        $keys = CmpRegion::orderBy('country')->get()->modelKeys();

        self::assertSame(
            [
                ['id' => 1, 'country' => 'DE'],
                ['id' => 1, 'country' => 'US'],
            ],
            $keys,
        );
    }

    /**
     * The composite #[ForeignKey] with a model-class reference and NULL
     * referencesColumns resolved to the target's full composite PK — the
     * DDL carries the two-column constraint (proven by SQLite's FK list).
     */
    public function testCompositeModelClassForeignKeyResolved(): void
    {
        $fks = $this->connection->selectSql('PRAGMA foreign_key_list(cmp_shipments)')->all();

        self::assertCount(2, $fks);
        self::assertSame('cmp_regions', $fks[0]->table);
        self::assertSame('cmp_regions', $fks[1]->table);

        $columns = array_column($fks, 'from');
        sort($columns);
        self::assertSame(['country', 'region_id'], $columns);

        $references = array_column($fks, 'to');
        sort($references);
        self::assertSame(['country', 'id'], $references);
    }

    /**
     * The schema-layer round-trip: the composite-PK table carries the
     * table-level PRIMARY KEY (no per-column inline PK).
     */
    public function testCompositePkDdl(): void
    {
        $rows = $this->connection->selectSql(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'cmp_regions'",
        );

        $ddlRow = $rows[0] ?? null;
        self::assertNotNull($ddlRow);
        self::assertStringContainsString('PRIMARY KEY', $ddlRow->sql);
        self::assertStringNotContainsString('id" integer NOT NULL PRIMARY KEY', $ddlRow->sql);
    }

    /**
     * The typed relation method hands back a NARROWED model — the caller
     * skips the local instanceof dance (the cache-backed read).
     */
    public function testGetRelationNarrowedByClass(): void
    {
        $this->seed();

        $shipments = CmpShipment::with('region')->orderBy('country')->get();
        self::assertNotNull($shipments[1]);
        $region = $shipments[1]->region()->get()->first();

        self::assertInstanceOf(CmpRegion::class, $region);
        self::assertSame('Alpha', $region->name);
    }

    /**
     * The typed relation method on a NULL relation resolves empty — the
     * null contract survives the cache-backed read (a null cache entry
     * wraps to an empty collection).
     */
    public function testGetRelationNarrowedNullStaysNull(): void
    {
        $this->seed();

        $orphan = new CmpShipment();
        $orphan->id = 99;
        $orphan->country = 'US';
        $orphan->title = 'Orphan';
        $orphan->save();

        $shipment = CmpShipment::find(99);
        self::assertNotNull($shipment);
        self::assertCount(0, $shipment->region()->get());
    }

    /**
     * A COMPOSED relation chain never serves the cache — the filters must
     * reach the database even when the relation is eagerly loaded.
     */
    public function testComposedRelationIgnoresCache(): void
    {
        $this->seed();

        $shipments = CmpShipment::with('region')->orderBy('country')->get();
        $shipment = $shipments[0];
        self::assertNotNull($shipment);

        // The unfiltered read rides the cache; the filtered read re-queries.
        self::assertCount(1, $shipment->region()->get());
        self::assertCount(0, $shipment->region()->where('name', '=', 'No Such Region')->get());
    }

    /**
     * Seed one tracked (soft-deleting) shipment per region, soft-delete the
     * DE one, and return the models.
     *
     * @return array{us: CmpTrackedShipment, de: CmpTrackedShipment}
     */
    private function seedTracked(): array
    {
        $this->seed();

        $us = new CmpTrackedShipment();
        $us->regionId = 1;
        $us->country = 'US';
        $us->title = 'Tracked US';
        $us->save();

        $de = new CmpTrackedShipment();
        $de->regionId = 1;
        $de->country = 'DE';
        $de->title = 'Tracked DE';
        $de->save();
        $de->delete();

        return ['us' => $us, 'de' => $de];
    }

    /**
     * Eager composite HasMany onto a SOFT-DELETING related model: the
     * OR-of-key-groups lands INSIDE one outer AND-group, so the
     * soft-delete scope ANDs against the whole set and the trashed child
     * stays excluded. (Pre-fix: the key groups ORed at the TOP level —
     * `(deleted_at IS NULL) OR (region_id = ? AND country = ?)` — and the
     * trashed child leaked back in whenever its key matched.)
     */
    public function testEagerCompositeHasManyExcludesTrashedChildren(): void
    {
        $this->seedTracked();

        $regions = CmpRegion::with('trackedShipments')->orderBy('country')->get();

        self::assertCount(2, $regions);
        self::assertNotNull($regions[0]);
        self::assertNotNull($regions[1]);
        $deShipments = $regions[0]->trackedShipments()->get();
        $usShipments = $regions[1]->trackedShipments()->get();
        self::assertInstanceOf(Collection::class, $deShipments);
        self::assertInstanceOf(Collection::class, $usShipments);
        self::assertCount(0, $deShipments, 'the trashed DE child must not leak through the eager load');
        self::assertCount(1, $usShipments);
    }

    /**
     * Eager composite HasOne onto a soft-deleting related model: same
     * scope composition — the trashed child stays excluded.
     */
    public function testEagerCompositeHasOneExcludesTrashedChildren(): void
    {
        $this->seedTracked();

        $regions = CmpRegion::with('primaryTrackedShipment')->orderBy('country')->get();

        self::assertCount(2, $regions);
        self::assertNotNull($regions[0]);
        self::assertNotNull($regions[1]);
        self::assertCount(0, $regions[0]->primaryTrackedShipment()->get());
        self::assertCount(1, $regions[1]->primaryTrackedShipment()->get());
    }

    /**
     * Eager composite BelongsTo FROM a soft-deleting child model: the
     * related (region) builder has no scope here — but the child's own
     * scope must not corrupt the eager query that targets the PARENT
     * table. Both children (one trashed) resolve their region.
     */
    public function testEagerCompositeBelongsToFromTrashedChild(): void
    {
        $this->seedTracked();

        $shipments = CmpTrackedShipment::newQuery()->withTrashed()->with('region')->orderBy('id')->get();

        self::assertCount(2, $shipments);
        self::assertNotNull($shipments[0]);
        self::assertNotNull($shipments[1]);
        $usRegion = $shipments[0]->region()->get()->first();
        $deRegion = $shipments[1]->region()->get()->first();
        self::assertInstanceOf(CmpRegion::class, $usRegion);
        self::assertInstanceOf(CmpRegion::class, $deRegion);
        self::assertSame('Alpha', $usRegion->name);
        self::assertSame('Beta', $deRegion->name);
    }
}
