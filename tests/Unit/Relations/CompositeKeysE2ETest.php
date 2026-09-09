<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpShipment;
use PHPUnit\Framework\TestCase;

/**
 * Live-SQLite E2E for composite-key paths: composite-PK find/save/delete,
 * composite relations (HasMany/HasOne/BelongsTo, lazy + eager), the
 * composite `whereKey()` guards, Collection key handling, and the
 * composite-PK model-class #[ForeignKey] resolution.
 */
class CompositeKeysE2ETest extends TestCase
{
    /**
     * The SQL connection the fixtures run on.
     *
     * @var \BlueprintAU\Radiant\Database\Connections\SqlConnection
     */
    private \BlueprintAU\Radiant\Database\Connections\SqlConnection $connection;

    /**
     * Build a :memory: SQLite manager and create the fixture tables.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        \BlueprintAU\Radiant\Database::setManager($manager);
        $this->connection = $manager->sqlConnection();

        $this->connection->create(Blueprint::fromMetadata(CmpRegion::class));
        $this->connection->create(Blueprint::fromMetadata(CmpShipment::class));
    }

    /**
     * Tear down the static facade so other tests are unaffected.
     */
    protected function tearDown(): void
    {
        \BlueprintAU\Radiant\Database::setManager(new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        parent::tearDown();
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

        try {
            $this->findUntyped(CmpRegion::class, ['id' => 1, 'region' => 'US']);
            self::fail('Expected an InvalidArgumentException for a non-PK key column.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('is not a primary key of model', $e->getMessage());
        }
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

        self::assertCount(1, $alpha->shipments()->getResults());
        self::assertSame('First', $alpha->shipments()->getResults()[0]->title);
        self::assertCount(1, $beta->shipments()->getResults());
        self::assertSame('Second', $beta->shipments()->getResults()[0]->title);
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
        $results = $alpha->shipments()->orWhere('title', '=', 'Second')->getResults();

        self::assertCount(2, $results);

        $titles = array_map(static fn (Model $shipment) => $shipment->attribute('title'), $results->all());
        sort($titles);
        self::assertSame(['First', 'Second'], $titles);

        // The decisive case: an OR filter matching a row OUTSIDE the tuple.
        // 'Second' belongs to (1, DE) — its presence proves the OR hit the
        // constraint's edge; if the tuple had leaked flat, the (1, DE)
        // 'First' row would ALSO appear (its fk1 part matches).
        self::assertNotContains('Second', $alpha->shipments()->getResults()->map(
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

        $shipment = $alpha->primaryShipment()->getResults();
        self::assertCount(1, $shipment);
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

        $region = $shipment->region()->getResults();
        self::assertCount(1, $region);
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
        // The class arg NARROWS statically — no local instanceof dance:
        // getRelation('shipments', CmpShipment::class) is
        // Collection<CmpShipment>|null, so ->first() is CmpShipment|null
        // and property access type-checks after the null assert.
        $deShipments = $regions[0]->getRelation('shipments', CmpShipment::class);
        $usShipments = $regions[1]->getRelation('shipments', CmpShipment::class);
        self::assertNotNull($deShipments);
        self::assertNotNull($usShipments);
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
        $deRegion = $shipments[0]->getRelation('region');
        $usRegion = $shipments[1]->getRelation('region');
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
        self::assertNull($shipments[2]->getRelation('region'));
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
        $region = $shipment->region()->getResults();
        self::assertCount(0, $region);

        // Eager BelongsTo: same empty result, no crash.
        $eager = CmpShipment::with('region')->orderBy('id')->get();
        self::assertNull($eager[2]->getRelation('region'));

        // And the OTHER direction: shipments() under a region with a NULL
        // part in its tuple — the region cannot own a partial match.
        $orphan = new CmpRegion();
        $orphan->id = 99;
        $orphan->country = '';
        $orphan->name = 'Ghost';
        $orphan->save();

        self::assertCount(0, $orphan->shipments()->getResults());
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

        self::assertStringContainsString('PRIMARY KEY', $rows[0]->sql);
        self::assertStringNotContainsString('id" integer NOT NULL PRIMARY KEY', $rows[0]->sql);
    }

    /**
     * getRelation() with the expected class hands back a NARROWED model —
     * the caller skips the local instanceof dance.
     */
    public function testGetRelationNarrowedByClass(): void
    {
        $this->seed();

        $shipments = CmpShipment::with('region')->orderBy('country')->get();
        $region = $shipments[1]->getRelation('region', CmpRegion::class);

        self::assertInstanceOf(CmpRegion::class, $region);
        self::assertSame('Alpha', $region->name);
    }

    /**
     * getRelation() with a class on a NULL relation stays null — the
     * null contract survives the narrowing parameter.
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
        self::assertNull($shipment->getRelation('region', CmpRegion::class));
    }

    /**
     * getRelation() with a WRONG expected class fails fast — a name/class
     * mismatch is a caller bug, not an empty result.
     */
    public function testGetRelationNarrowedMismatchThrows(): void
    {
        $this->seed();

        $shipments = CmpShipment::with('region')->get();
        $shipment = $shipments[0];

        try {
            $this->relationWrongClass($shipment, 'region', CmpShipment::class);
            self::fail('Expected the class mismatch to throw.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('holds a [', $e->getMessage());
            self::assertStringContainsString('CmpShipment] was expected', $e->getMessage());
        }
    }

    /**
     * Call getRelation() with the boundary parameters under test.
     *
     * @param Model $model The model holding the relation.
     * @param string $name The relation name.
     * @param class-string<\BlueprintAU\Radiant\Model> $related The expected class.
     * @return \BlueprintAU\Radiant\Model|\BlueprintAU\Radiant\Collection<\BlueprintAU\Radiant\Model>|null
     */
    private function relationWrongClass(Model $model, string $name, string $related): Model|Collection|null
    {
        return $model->getRelation($name, $related);
    }
}
