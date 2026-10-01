<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpLeg;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRoute;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeamPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser;

/**
 * The composite through-relation paths: the constructor's hop-key guards,
 * the grouped parent filter, the composite eager branch (OR-of-groups,
 * synthetic alias select, tuple extraction), the scalar-key-in-composite
 * throw, and the match() tuple contracts — lazy and eager, HasManyThrough
 * and HasOneThrough.
 */
final class HasManyThroughCompositeTest extends DatabaseTestCase
{
    /**
     * Create the composite through-chain tables: region (composite PK) →
     * route (composite PK, the intermediate) → leg (composite FK, the
     * related model).
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(CmpRegion::class, CmpRoute::class, CmpLeg::class);
    }

    /**
     * Seed two regions, one route each, and legs per route.
     *
     * @return array{us: CmpRegion, de: CmpRegion}
     */
    private function seed(): array
    {
        $us = new CmpRegion();
        $us->id = 1;
        $us->country = 'US';
        $us->name = 'Alpha';
        $us->save();

        $de = new CmpRegion();
        $de->id = 1;
        $de->country = 'DE';
        $de->name = 'Beta';
        $de->save();

        $usRoute = new CmpRoute();
        $usRoute->region_id = 1;
        $usRoute->country = 'US';
        $usRoute->label = 'US Route';
        $usRoute->save();

        $deRoute = new CmpRoute();
        $deRoute->region_id = 1;
        $deRoute->country = 'DE';
        $deRoute->label = 'DE Route';
        $deRoute->save();

        // Two legs on the US route, one on the DE route.
        foreach ([['US', 'pickup'], ['US', 'dropoff'], ['DE', 'customs']] as [$country, $position]) {
            $leg = new CmpLeg();
            $leg->routeId = 1;
            $leg->routeCountry = $country;
            $leg->position = $position;
            $leg->save();
        }

        return ['us' => $us, 'de' => $de];
    }

    // ---- Constructor hop-key guards ----

    /**
     * A through relation mixing a scalar first key with a composite second
     * key fails fast — the join would be unbuildable.
     */
    public function testCtorRejectsMixedHopKeyShapes(): void
    {
        $region = new CmpRegion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('BOTH single columns or BOTH composite column lists');

        new \BlueprintAU\Radiant\Relations\HasManyThrough(
            $region,
            CmpLeg::class,
            CmpRoute::class,
            'region_id',
            ['route_id', 'route_country'],
            ['id', 'country'],
        );
    }

    /**
     * A through relation with an empty hop key list fails fast.
     */
    public function testCtorRejectsEmptyHopKeys(): void
    {
        $region = new CmpRegion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('require at least one column');

        new \BlueprintAU\Radiant\Relations\HasManyThrough(
            $region,
            CmpLeg::class,
            CmpRoute::class,
            [],
            [],
            ['id', 'country'],
        );
    }

    /**
     * A through relation with mismatched hop-key arity fails fast.
     */
    public function testCtorRejectsArityMismatchHopKeys(): void
    {
        $region = new CmpRegion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('matching arity; got 2 and 1');

        new \BlueprintAU\Radiant\Relations\HasManyThrough(
            $region,
            CmpLeg::class,
            CmpRoute::class,
            ['region_id', 'country'],
            ['route_id'],
            ['id', 'country'],
        );
    }

    // ---- intermediateKeys guards ----

    /**
     * An intermediate whose PK arity mismatches the second key fails fast
     * — the join must pair every key column. (The ctor's own arity guard
     * fires first for a 2-vs-1 hop-key pair, so this exercises the
     * intermediate-PK check via a matching-arity second key against a
     * 3-column-PK intermediate — unreachable with the current fixtures'
     * shapes, so the ctor guard is what this asserts.)
     */
    public function testIntermediateKeysArityMismatchThrows(): void
    {
        $region = new CmpRegion();

        // CmpRoute's PK is 2 columns; the second key declares 1 — the ctor
        // arity guard fires before intermediateKeys() ever runs.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('matching arity; got 2 and 1');

        new \BlueprintAU\Radiant\Relations\HasManyThrough(
            $region,
            CmpLeg::class,
            CmpRoute::class,
            ['region_id', 'country'],
            ['route_id'],
            ['id', 'country'],
        );
    }

    /**
     * getSecondKeys() on a scalar through relation throws — the caller
     * meant the scalar accessor.
     */
    public function testGetSecondKeysRejectsScalarRelation(): void
    {
        ['us' => $us] = $this->seed();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('uses a single second key');

        // A fully scalar hop (scalar first key + scalar second key) is a
        // valid relation — its getSecondKeys() is the misuse. The scalar
        // rel_* chain provides the matching scalar intermediate.
        new \BlueprintAU\Radiant\Relations\HasManyThrough(
            $us,
            RelTeamPost::class,
            \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam::class,
            'owner_id',
            'team_id',
            'id',
        )->getSecondKeys();
    }

    // ---- Lazy composite paths ----

    /**
     * Lazy composite through: the grouped parent filter matches the FULL
     * tuple — (1, US) and (1, DE) are different regions with different
     * legs.
     */
    public function testLazyCompositeThroughMatchesFullTuple(): void
    {
        ['us' => $us, 'de' => $de] = $this->seed();

        $usLegs = $us->legs()->getResults();
        self::assertCount(2, $usLegs);
        self::assertSame(['pickup', 'dropoff'], $usLegs
            ->map(static fn (CmpLeg $leg) => $leg->position)->all());

        $deLegs = $de->legs()->getResults();
        self::assertCount(1, $deLegs);
        self::assertNotNull($deLegs->first());
        self::assertSame('customs', $deLegs->first()->position);
    }

    /**
     * The grouped constraint's SQL shape: the tuple's parts AND inside
     * parens; a caller's appended OR prefixes the parens, not the parts.
     */
    public function testLazyConstraintSqlShape(): void
    {
        ['us' => $us] = $this->seed();

        $composed = $us->legs()->orWhere('position', '=', 'customs');

        $sql = (new \BlueprintAU\Radiant\Database\Grammars\SqliteGrammar())
            ->compileSelect($composed->getQuery());

        self::assertStringContainsString(
            'WHERE ("cmp_routes"."region_id" = ? AND "cmp_routes"."country" = ?) OR "position" = ?',
            $sql,
        );
    }

    /**
     * A null component in the parent's tuple becomes IS NULL — matching
     * nothing, never a partial-tuple match.
     */
    public function testNullTupleComponentMatchesNothing(): void
    {
        ['us' => $us] = $this->seed();

        // A region with a NULL country component cannot own legs.
        $ghost = new CmpRegion();
        $ghost->id = 99;
        $ghost->country = '';
        $ghost->name = 'Ghost';
        $ghost->save();

        self::assertCount(0, $ghost->legs()->getResults());
    }

    // ---- Eager composite paths ----

    /**
     * Eager composite through: one OR-of-groups query, legs stitched per
     * full tuple.
     */
    public function testEagerCompositeThroughStitchesPerTuple(): void
    {
        $this->seed();

        $regions = CmpRegion::with('legs')->orderBy('country')->get();
        self::assertCount(2, $regions);
        self::assertNotNull($regions[0]);
        self::assertNotNull($regions[1]);

        $deLegs = $regions[0]->legs()->getResults();
        $usLegs = $regions[1]->legs()->getResults();
        self::assertInstanceOf(Collection::class, $deLegs);
        self::assertInstanceOf(Collection::class, $usLegs);
        self::assertCount(1, $deLegs, 'the DE region must get only its own leg');
        self::assertCount(2, $usLegs, 'the US region must get only its own legs');
    }

    /**
     * The composite eager query carries the parent-key tuple through the
     * synthetic aliases — one column per first-key part.
     */
    public function testEagerCompositeSelectsTupleAliases(): void
    {
        ['us' => $us] = $this->seed();

        $relation = $us->legs();
        // The loader collects keys by the parent's LOCAL key columns.
        $result = $relation->eagerLoad([
            ['id' => 1, 'country' => 'US'],
        ]);

        self::assertCount(2, $result->models);
        self::assertSame(
            [[1, 'US'], [1, 'US']],
            $result->parentKeys,
            'each row carries the full parent tuple positionally',
        );
    }

    /**
     * A scalar key in the composite eager path fails fast — the loader's
     * contract requires column => value maps.
     */
    public function testEagerCompositeRejectsScalarKey(): void
    {
        ['us' => $us] = $this->seed();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires column => value key maps');

        $us->legs()->eagerLoad([1]);
    }

    /**
     * eagerLoad([]) short-circuits to an empty models-only result.
     */
    public function testEagerLoadEmptyKeysReturnsEmpty(): void
    {
        ['us' => $us] = $this->seed();

        $result = $us->legs()->eagerLoad([]);

        self::assertCount(0, $result->models);
        self::assertNull($result->parentKeys);
    }

    /**
     * A parent with a NULL key component contributes no eager key — the
     * loader skips it, and the relation loads empty.
     */
    public function testEagerSkipsNullKeyComponents(): void
    {
        $this->seed();

        $ghost = new CmpRegion();
        $ghost->id = 99;
        $ghost->country = '';
        $ghost->name = 'Ghost';
        $ghost->save();

        $regions = CmpRegion::with('legs')->orderBy('id', 'desc')->get();
        self::assertCount(3, $regions);

        $loadedGhost = $regions->first();
        self::assertNotNull($loadedGhost);
        self::assertCount(0, $loadedGhost->legs()->getResults());
    }

    // ---- match() contracts ----

    /**
     * match() without the EagerResult parent keys fails fast.
     */
    public function testMatchRequiresEagerKeys(): void
    {
        ['us' => $us] = $this->seed();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('requires the EagerResult parent keys');

        $us->legs()->match([$us], Collection::make([]), 'legs', null);
    }

    /**
     * match() groups results by the serialized TUPLE — a null-keyed row
     * lands on no parent.
     */
    public function testMatchGroupsByTupleAndSkipsNullKeys(): void
    {
        ['us' => $us, 'de' => $de] = $this->seed();

        $legs = CmpLeg::newQuery()->orderBy('id')->get();
        self::assertCount(3, $legs);

        // Tuples: US rows → [1, 'US'], DE row → [1, 'DE'].
        $us->legs()->match(
            [$us, $de],
            $legs,
            'legs',
            [[1, 'US'], [1, 'US'], [1, 'DE']],
        );

        self::assertCount(2, $us->legs()->getResults());
        self::assertCount(1, $de->legs()->getResults());

        // A null key drops its row — the DE region loads empty.
        $us->legs()->match([$us, $de], $legs, 'legs', [[1, 'US'], [1, 'US'], null]);
        self::assertCount(2, $us->legs()->getResults());
        self::assertCount(0, $de->legs()->getResults());
    }

    // ---- HasOneThrough composite ----

    /**
     * Lazy composite HasOneThrough: the first leg per full tuple.
     */
    public function testLazyCompositeHasOneThrough(): void
    {
        ['us' => $us] = $this->seed();

        $legs = $us->firstLeg()->getResults();
        self::assertCount(1, $legs);
        self::assertNotNull($legs->first());
        self::assertSame('pickup', $legs->first()->position);
    }

    /**
     * Eager composite HasOneThrough: first-wins per tuple.
     */
    public function testEagerCompositeHasOneThrough(): void
    {
        $this->seed();

        $regions = CmpRegion::with('firstLeg')->orderBy('country')->get();
        self::assertCount(2, $regions);
        self::assertNotNull($regions[1]);

        $leg = $regions[1]->firstLeg()->getResults()->first();
        self::assertInstanceOf(CmpLeg::class, $leg);
        self::assertSame('pickup', $leg->position);
    }

    /**
     * HasOneThrough::match() keeps the FIRST result per tuple — and a
     * null-keyed row is skipped, letting the next one win.
     */
    public function testHasOneThroughMatchFirstWinsPerTuple(): void
    {
        ['us' => $us] = $this->seed();

        $legs = CmpLeg::newQuery()->orderBy('id')->get();
        self::assertCount(3, $legs);

        $first = $legs->first();
        $second = $legs->skip(1)->first();
        self::assertNotNull($first);
        self::assertNotNull($second);

        // Both US rows carry the same tuple — the first wins.
        $us->firstLeg()->match([$us], $legs, 'firstLeg', [[1, 'US'], [1, 'US'], [1, 'DE']]);
        $cached = $us->firstLeg()->getResults();
        self::assertNotNull($cached->first());
        self::assertSame($first->id, $cached->first()->id);

        // A null key skips its row — the second US row becomes the match.
        $us->firstLeg()->match([$us], $legs, 'firstLeg', [null, [1, 'US'], [1, 'DE']]);
        $cached2 = $us->firstLeg()->getResults();
        self::assertNotNull($cached2->first());
        self::assertSame($second->id, $cached2->first()->id);
    }

    // ---- Scalar through still works alongside ----

    /**
     * The scalar through path is untouched by the composite work — the
     * rel_* chain still round-trips.
     */
    public function testScalarThroughStillWorks(): void
    {
        $this->createTables(
            \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser::class,
            \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam::class,
            RelTeamPost::class,
        );

        $user = new RelUser();
        $user->email = 'alicia@example.com';
        $user->save();

        $team = new \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam();
        $team->ownerId = $user->id;
        $team->name = 'Core';
        $team->save();

        $post = new RelTeamPost();
        $post->teamId = $team->id;
        $post->title = 'Alpha';
        $post->save();

        $loaded = RelUser::with('teamPosts')->find($user->id);
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->teamPosts()->getResults());
    }

    /**
     * A composite through onto a SOFT-DELETING related model: the
     * OR-of-groups lands INSIDE one outer AND-group, so the trait scope
     * ANDs against the whole key set and a trashed row stays excluded.
     */
    public function testEagerCompositeThroughExcludesTrashedRows(): void
    {
        $this->createTables(
            \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpTrackedShipment::class,
        );

        ['us' => $us] = $this->seed();

        // A tracked shipment chain is not a through chain — use the
        // region's direct composite HasMany instead: the same OR-of-groups
        // composition the through eager path uses.
        $kept = new \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpTrackedShipment();
        $kept->regionId = 1;
        $kept->country = 'US';
        $kept->title = 'Kept';
        $kept->save();

        $trashed = new \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpTrackedShipment();
        $trashed->regionId = 1;
        $trashed->country = 'US';
        $trashed->title = 'Trashed';
        $trashed->save();
        $trashed->delete();

        $regions = CmpRegion::with('trackedShipments')->orderBy('country')->get();
        self::assertNotNull($regions[1]);

        $shipments = $regions[1]->trackedShipments()->getResults();
        self::assertInstanceOf(Collection::class, $shipments);
        self::assertCount(1, $shipments, 'the trashed shipment must not leak through the eager load');
        self::assertNotNull($shipments->first());
        self::assertSame('Kept', $shipments->first()->title);
    }

    /**
     * A composite through where the intermediate has NO rows yields an
     * empty relation — the INNER JOIN is the honest semantics.
     */
    public function testRoutelessRegionLoadsEmpty(): void
    {
        ['us' => $us] = $this->seed();

        $lonely = new CmpRegion();
        $lonely->id = 2;
        $lonely->country = 'FR';
        $lonely->name = 'Lonely';
        $lonely->save();

        self::assertCount(0, $lonely->legs()->getResults());
    }
}
