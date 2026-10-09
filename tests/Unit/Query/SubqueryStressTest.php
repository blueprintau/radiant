<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Query;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\SubquerySelect;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * The subquery-vocabulary stress query, end to end on live SQLite.
 *
 * Reproduces the profiles/memberships/circles anti-join shape that
 * motivated the vocabulary: a self-join over ordered handles, two
 * correlated NOT EXISTS constraints, a scalar-subquery select column
 * (the exclusive circle list via GROUP_CONCAT), and a group aggregate
 * (the MIN witness) — all expressed through the typed builder API with
 * the subqueries as structured QueryBuilders.
 *
 * Domain: profiles pair up when they share a circle that the OTHER
 * profile is NOT in (the `witness` circle), and the `exclusive_list`
 * column names the circles p1 belongs to that p2 does not.
 */
final class SubqueryStressTest extends DatabaseTestCase
{
    /**
     * Create the stress fixtures: profiles, circles, and memberships
     * with disjoint per-profile circles so every ordered handle pair
     * produces a witness row.
     */
    protected function setUpDatabase(): void
    {
        $profiles = (new Blueprint('profiles'))
            ->id()
            ->string('handle', 64)
            ->string('mobile_number', 32);
        $circles = (new Blueprint('circles'))
            ->id()
            ->string('handle', 64);
        $memberships = (new Blueprint('memberships'))
            ->column(ColumnType::BigInt, 'profile_id')
            ->column(ColumnType::BigInt, 'circle_id');

        $this->createTablesUntracked($profiles, $circles, $memberships);

        // Profiles: handles ordered so `p2.handle > p1.handle` pairs each
        // profile only with LATER handles.
        $this->connection->table('profiles')->insert([
            ['id' => 1, 'handle' => 'alpha', 'mobile_number' => '100'],
            ['id' => 2, 'handle' => 'beta', 'mobile_number' => '200'],
            ['id' => 3, 'handle' => 'gamma', 'mobile_number' => '300'],
        ]);


        // Membership semantics the stress query demands:
        //
        //   The witness constraint needs, for a (p1, p2) pair, a circle c
        //   of p1 that p2 is NOT in (NOT EXISTS x: x.profile_id = p2 AND
        //   x.circle_id = c), AND no shared circle at all (the second NOT
        //   EXISTS over co-memberships).
        //
        //   alpha  → {11, 12}
        //   beta   → {13}
        //   gamma  → {14}
        //
        //   Pairs (alpha,beta): alpha's circles ∉ beta = {11,12} → witness
        //   MIN = 'circle-a'. No shared circles → passes. exclusive_list
        //   for alpha vs beta = {11,12} (GROUP_CONCAT order = membership
        //   insertion order).
        //   Pairs (alpha,gamma): witness {11,12} → 'circle-a'.
        //   Pairs (beta,gamma): beta's {13} ∉ gamma → 'circle-b'.
        $this->connection->table('circles')->insert([
            ['id' => 11, 'handle' => 'circle-a'],
            ['id' => 12, 'handle' => 'circle-a'],
            ['id' => 13, 'handle' => 'circle-b'],
            ['id' => 14, 'handle' => 'circle-c'],
        ]);
        $this->connection->table('memberships')->insert([
            ['profile_id' => 1, 'circle_id' => 11],
            ['profile_id' => 1, 'circle_id' => 12],
            ['profile_id' => 2, 'circle_id' => 13],
            ['profile_id' => 3, 'circle_id' => 14],
        ]);
    }

    /**
     * The typed-builder translation of the motivating SQL. Every subquery
     * is a structured QueryBuilder — the correlated references ride the
     * base builder (no model allowlist), the aggregate/alias machinery is
     * the typed Aggregate node.
     *
     * @return list<array<string, mixed>>
     */
    private function runStressQuery(): array
    {
        // exclusive_list: GROUP_CONCAT over the circles p1 belongs to and
        // p2 does not — a scalar subquery correlated to BOTH outer aliases.
        $exclusiveList = $this->baseQuery('memberships as a')
            ->join('circles as cc', 'cc.id', '=', 'a.circle_id')
            ->select(new Expression('GROUP_CONCAT(cc.handle)'))
            ->whereColumn('a.profile_id', '=', 'p1.id')
            ->whereNotExists(
                $this->baseQuery('memberships as x')
                    ->select(new Expression('1'))
                    ->whereColumn('x.profile_id', '=', 'p2.id')
                    ->whereColumn('x.circle_id', '=', 'a.circle_id'),
            );

        $builder = $this->baseQuery('profiles as p1')
            ->join('profiles as p2', 'p2.handle', '>', 'p1.handle')
            ->join('memberships as m1', 'm1.profile_id', '=', 'p1.id')
            ->join('circles as c', 'c.id', '=', 'm1.circle_id')
            ->whereNotExists(
                $this->baseQuery('memberships as x')
                    ->select(new Expression('1'))
                    ->whereColumn('x.profile_id', '=', 'p2.id')
                    ->whereColumn('x.circle_id', '=', 'c.id'),
            )
            ->whereNotExists(
                $this->baseQuery('memberships as a')
                    ->join('memberships as b', 'b.circle_id', '=', 'a.circle_id')
                    ->select(new Expression('1'))
                    ->whereColumn('a.profile_id', '=', 'p1.id')
                    ->whereColumn('b.profile_id', '=', 'p2.id'),
            )
            ->select(
                'p1.handle as handle1',
                'p2.handle as handle2',
                'p1.mobile_number as mobile1',
                'p2.mobile_number as mobile2',
                new Expression('0 as shared'),
                new Aggregate('MIN', 'c.handle', 'witness'),
                new SubquerySelect($exclusiveList, 'exclusive_list'),
            )
            ->groupBy('p1.id', 'p2.id')
            ->orderBy('p1.id')
            ->orderBy('p2.id');

        $rows = [];
        foreach ($this->connection->select($builder) as $row) {
            $rows[] = (array) $row;
        }
        return $rows;
    }

    /**
     * A base QueryBuilder over a (possibly aliased) table — the correlated
     * subquery vehicle, no model allowlist.
     *
     * @param  string  $table
     * @return QueryBuilder
     */
    private function baseQuery(string $table): QueryBuilder
    {
        return new QueryBuilder($this->connection, $table);
    }

    /**
     * The full stress query runs against live SQLite and returns every
     * ordered pair with its MIN witness circle handle — all three pairs
     * qualify because the seed gives each profile disjoint circles.
     */
    public function testStressQueryReturnsWitnessPairs(): void
    {
        $rows = $this->runStressQuery();

        self::assertCount(3, $rows);

        $pairs = array_map(fn(array $r) => [$r['handle1'], $r['handle2']], $rows);
        self::assertSame([['alpha', 'beta'], ['alpha', 'gamma'], ['beta', 'gamma']], $pairs);

        // Witness = MIN over p1's circles that p2 is not in — the join
        // enumerates (p1, p2, c) rows, the GROUP BY collapses them.
        $witnesses = array_map(fn(array $r) => $r['witness'], $rows);
        self::assertSame(['circle-a', 'circle-a', 'circle-b'], $witnesses);

        // The literal 0 column rides the raw Expression.
        self::assertSame([0, 0, 0], array_map(fn(array $r) => $r['shared'], $rows));
    }

    /**
     * The scalar-subquery column carries each pair's exclusive circle
     * list — the circles p1 holds that p2 does not, computed by the
     * nested NOT EXISTS inside the GROUP_CONCAT subquery.
     */
    public function testStressQueryExclusiveListColumn(): void
    {
        $rows = $this->runStressQuery();

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['handle1'] . '|' . $row['handle2']] = $row['exclusive_list'];
        }

        // alpha's circles that beta lacks: {11, 12} — GROUP_CONCAT joins
        // them comma-separated. The nested NOT EXISTS inside the scalar
        // subquery does the per-circle exclusion.
        self::assertSame('circle-a,circle-a', $byKey['alpha|beta']);
        self::assertSame('circle-a,circle-a', $byKey['alpha|gamma']);
        // beta vs gamma: {13}.
        self::assertSame('circle-b', $byKey['beta|gamma']);
    }

    /**
     * The mobile aliases flow through the qualified select specs.
     */
    public function testStressQueryMobileAliases(): void
    {
        $rows = $this->runStressQuery();

        self::assertSame('100', $rows[0]['mobile1']);
        self::assertSame('200', $rows[0]['mobile2']);
    }
}
