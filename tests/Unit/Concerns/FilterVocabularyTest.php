<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Concerns;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Fixture: filter-vocabulary parent.
 */
#[Table(name: 'fv_users')]
class FvUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The user's age (0 = unknown).
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $age;

    /**
     * The user's posts.
     *
     * @return \BlueprintAU\Radiant\Relations\HasMany<FvPost>
     */
    public function posts(): \BlueprintAU\Radiant\Relations\HasMany
    {
        return $this->hasMany(FvPost::class, 'user_id');
    }
}

/**
 * Fixture: filter-vocabulary child.
 */
#[Table(name: 'fv_posts')]
class FvPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The author's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'user_id')]
    public int $userId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;

    /**
     * The view count.
     *
     * @var int
     */
    #[Column(type: ColumnType::Int)]
    public int $views;

    /**
     * The post's author.
     *
     * @return \BlueprintAU\Radiant\Relations\BelongsTo<FvUser>
     */
    public function author(): \BlueprintAU\Radiant\Relations\BelongsTo
    {
        return $this->belongsTo(FvUser::class, 'user_id');
    }
}

/**
 * The shared filter traits, exercised method by method.
 *
 * Static side ({@see \BlueprintAU\Radiant\Concerns\FiltersStaticQuery}) via
 * `FvUser::method()`; instance side ({@see \BlueprintAU\Radiant\Concerns\FiltersQuery})
 * via `$user->posts()->method()`. Every filter is asserted against live
 * SQLite results, not compiled SQL — the traits must produce the same
 * RESULTS the builder does.
 *
 * The typed chains double as generic-flow assertions: each `$row->prop`
 * access type-checks only when the trait's `ModelQueryBuilder<static>`
 * return carries the concrete model through `get()`/`first()`.
 */
final class FilterVocabularyTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables from the models' attributes and seed three
     * users + four posts.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(FvUser::class, FvPost::class);

        // alicia (30): two posts. ben (40): one post. cara (null age): one post.
        foreach ([['alicia', 30], ['ben', 40], ['cara', null]] as [$name, $age]) {
            $user = new FvUser();
            $user->name = $name;
            $user->age = $age;
            $user->save();
        }

        $seed = [
            ['alicia', 'A1', 100], ['alicia', 'A2', 200],
            ['ben', 'B1', 300], ['cara', 'C1', 400],
        ];
        foreach ($seed as [$author, $title, $views]) {
            $user = FvUser::where('name', '=', $author)->first();
            self::assertNotNull($user);
            $post = new FvPost();
            $post->userId = $user->id;
            $post->title = $title;
            $post->views = $views;
            $post->save();
        }
    }

    // ---- Static trait: where-family ----

    /**
     * Static orWhere() composes onto the sink.
     */
    public function testStaticOrWhere(): void
    {
        $rows = FvUser::where('name', '=', 'alicia')
            ->orWhere('name', '=', 'ben')
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame('ben', $rows[1]->name);
    }

    /**
     * Static whereIn() filters to the given values.
     */
    public function testStaticWhereIn(): void
    {
        $rows = FvUser::whereIn('name', ['alicia', 'cara'])->orderBy('id')->get();

        self::assertCount(2, $rows);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame('cara', $rows[1]->name);
    }

    /**
     * Static whereNotIn() excludes the given values.
     */
    public function testStaticWhereNotIn(): void
    {
        $rows = FvUser::whereNotIn('name', ['alicia', 'ben'])->get();

        self::assertCount(1, $rows);
        self::assertSame('cara', $rows[0]->name);
    }

    /**
     * Static whereNull()/whereNotNull() on a nullable column.
     */
    public function testStaticWhereNullAndNotNull(): void
    {
        $nulls = FvUser::whereNull('age')->get();
        self::assertCount(1, $nulls);
        self::assertSame('cara', $nulls[0]->name);
        self::assertNull($nulls[0]->age);

        $notNulls = FvUser::whereNotNull('age')->orderBy('id')->get();
        self::assertCount(2, $notNulls);
        self::assertSame('alicia', $notNulls[0]->name);
        self::assertSame('ben', $notNulls[1]->name);
    }

    /**
     * Static whereBetween()/whereNotBetween() bound both ends. The NULL
     * age row falls outside BOTH (SQL NULL semantics — a NULL is neither
     * between nor not-between).
     */
    public function testStaticWhereBetweenAndNotBetween(): void
    {
        $between = FvUser::whereBetween('age', [30, 39])->get();
        self::assertCount(1, $between);
        self::assertSame('alicia', $between[0]->name);

        $outside = FvUser::whereNotBetween('age', [30, 39])->orderBy('id')->get();
        self::assertCount(1, $outside);
        self::assertSame('ben', $outside[0]->name);
    }

    // ---- Static trait: shape + aggregation ----

    /**
     * Static limit()/offset() page correctly.
     */
    public function testStaticLimitOffset(): void
    {
        $page = FvUser::orderBy('id')->offset(1)->limit(1)->get();

        self::assertCount(1, $page);
        self::assertSame('ben', $page[0]->name);
    }

    /**
     * Static select() narrows the columns. The forced-PK merge is
     * documented behavior — the PK always rides so hydration and whereKey
     * work — so the raw row carries id + name.
     */
    public function testStaticSelect(): void
    {
        $rows = FvUser::select('name')->orderBy('id')->getRaw();

        self::assertCount(3, $rows);
        self::assertSame(['id', 'name'], array_keys(get_object_vars($rows[0])));
        self::assertSame('alicia', $rows[0]->name);
    }

    /**
     * Static groupBy()/having() aggregate correctly.
     */
    public function testStaticGroupByHaving(): void
    {
        $counts = FvPost::select('user_id')
            ->groupBy('user_id')
            ->having(Aggregate::count(), '>', 1)
            ->getRaw();

        self::assertCount(1, $counts, 'only alicia (id 1) has more than one post');
        self::assertSame(1, $counts[0]->user_id);
    }

    // ---- Instance trait (relation) ----

    /**
     * The relation's whereIn()/whereNotIn() filter the constrained query.
     */
    public function testRelationWhereInAndNotIn(): void
    {
        ['user' => $user] = $this->seedFirst();

        $in = $user->posts()->whereIn('title', ['A1', 'B1'])->getResults();
        self::assertCount(1, $in);
        self::assertSame('A1', $in[0]->title);

        $notIn = $user->posts()->whereNotIn('title', ['A1'])->getResults();
        self::assertCount(1, $notIn);
        self::assertSame('A2', $notIn[0]->title);
    }

    /**
     * The relation's whereNull()/whereNotNull() — the constraint still rides.
     */
    public function testRelationWhereNullAndNotNull(): void
    {
        ['user' => $user] = $this->seedFirst();

        $notNull = $user->posts()->whereNotNull('views')->getResults();
        self::assertCount(2, $notNull);
    }

    /**
     * The relation's whereBetween()/whereNotBetween().
     */
    public function testRelationWhereBetweenAndNotBetween(): void
    {
        ['user' => $user] = $this->seedFirst();

        $low = $user->posts()->whereBetween('views', [0, 150])->getResults();
        self::assertCount(1, $low);
        self::assertSame('A1', $low[0]->title);

        $high = $user->posts()->whereNotBetween('views', [0, 150])->getResults();
        self::assertCount(1, $high);
        self::assertSame('A2', $high[0]->title);
    }

    /**
     * The relation's orWhere() composes with the constructor constraint.
     */
    public function testRelationOrWhere(): void
    {
        ['user' => $user] = $this->seedFirst();

        $rows = $user->posts()
            ->where('title', '=', 'A1')
            ->orWhere('views', '=', 200)
            ->getResults();

        self::assertCount(2, $rows, 'both posts match under OR');
    }

    /**
     * The relation's limit()/offset() page within the constrained set.
     */
    public function testRelationLimitOffset(): void
    {
        ['user' => $user] = $this->seedFirst();

        $page = $user->posts()->orderBy('title')->offset(1)->limit(1)->getResults();

        self::assertCount(1, $page);
        self::assertSame('A2', $page[0]->title);
    }

    /**
     * The relation's select()/groupBy()/having() aggregate the related
     * set. Raw rows come off getQuery() — getRaw() is builder-only.
     */
    public function testRelationSelectGroupByHaving(): void
    {
        ['user' => $user] = $this->seedFirst();

        $rows = $user->posts()
            ->select('title')
            ->groupBy('title')
            ->having(Aggregate::count(), '>', 0)
            ->orderBy('title')
            ->getQuery()
            ->getRaw();

        self::assertCount(2, $rows);
        self::assertSame('A1', $rows[0]->title);
        self::assertSame('A2', $rows[1]->title);
    }

    /**
     * The relation accessors expose the wiring — and getQuery() hands back
     * the CONSTRAINED builder (the constraint is inside).
     */
    public function testRelationAccessors(): void
    {
        ['user' => $user] = $this->seedFirst();

        $relation = $user->posts();

        self::assertSame(FvPost::class, $relation->getRelated());
        self::assertSame('user_id', $relation->getForeignKey());
        self::assertSame('id', $relation->getLocalKey());

        $raw = $relation->getQuery()->getRaw();
        self::assertCount(2, $rows = $raw->all(), 'getQuery() carries the constraint');
        self::assertSame('A1', $rows[0]->title);
    }

    /**
     * Seed and return the first user with their two posts.
     *
     * @return array{user: FvUser}
     */
    private function seedFirst(): array
    {
        /** @var FvUser|null $user */
        $user = FvUser::where('name', '=', 'alicia')->first();
        self::assertNotNull($user);

        return ['user' => $user];
    }
}
