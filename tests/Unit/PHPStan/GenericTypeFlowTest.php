<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures\TypeFlowPost;
use BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures\TypeFlowUser;
use PHPUnit\Framework\TestCase;

/**
 * The generic-typing contract, asserted at RUNTIME.
 *
 * The static half of this contract is checked by PHPStan (level 8, no
 * baseline) against these fixtures and every typed chain in the suite;
 * Intelephense reads the same declarations — get_errors on this tree is
 * the IDE-side check. This test locks the RUNTIME half: the classes the
 * analyzers resolve must actually BE the classes delivered, so a
 * docblock/runtime drift fails loudly here rather than silently in an
 * IDE.
 */
final class GenericTypeFlowTest extends TestCase
{
    /**
     * The live SQLite connection.
     *
     * @var SqlConnection
     */
    private SqlConnection $connection;

    /**
     * Build a :memory: SQLite manager and the fixture tables.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        \BlueprintAU\Radiant\Database::setManager($manager);
        $this->connection = $manager->sqlConnection();

        $this->connection->create((new Blueprint('type_flow_users'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'name', length: 64));

        $this->connection->create((new Blueprint('type_flow_posts'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::BigInt, 'user_id')
            ->column(ColumnType::String, 'title', length: 64));

        $user = new TypeFlowUser();
        $user->name = 'alicia';
        $user->save();

        foreach (['one', 'two'] as $title) {
            $post = new TypeFlowPost();
            $post->userId = $user->id;
            $post->title = $title;
            $post->save();
        }
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
     * The static forwarder's builder is bound to the CALLING class — the
     * generic the analyzers resolve (`ModelQueryBuilder<static>`) is the
     * one delivered at runtime.
     */
    public function testStaticForwarderBuilderBindsToCallingClass(): void
    {
        $builder = TypeFlowUser::where('name', '=', 'alicia');

        self::assertInstanceOf(ModelQueryBuilder::class, $builder);
        self::assertSame(TypeFlowUser::class, $builder->modelClass);

        $model = $builder->first();
        self::assertInstanceOf(TypeFlowUser::class, $model, 'first() hydrates the CONCRETE class');
        self::assertSame('alicia', $model->name);
    }

    /**
     * The relation's getResults() delivers the declared TRelated — the
     * generic the fixture's `HasMany<TypeFlowPost>` promises.
     */
    public function testRelationDeliversDeclaredRelatedType(): void
    {
        $user = TypeFlowUser::newQuery()->first();
        self::assertNotNull($user);

        $posts = $user->posts()->getResults();

        self::assertInstanceOf(Collection::class, $posts);
        foreach ($posts as $post) {
            self::assertInstanceOf(TypeFlowPost::class, $post);
        }
        self::assertSame('one', $posts[0]->title);
        self::assertSame('two', $posts[1]->title);
    }

    /**
     * The eager loader delivers the declared related type onto the parent.
     */
    public function testEagerLoadDeliversDeclaredRelatedType(): void
    {
        $user = TypeFlowUser::with('posts')->first();
        self::assertNotNull($user);

        $posts = $user->getRelation('posts');
        self::assertInstanceOf(Collection::class, $posts);
        foreach ($posts as $post) {
            self::assertInstanceOf(TypeFlowPost::class, $post);
        }
        self::assertTrue($user->relationLoaded('posts'));
    }

    /**
     * getQuery() hands back the constrained builder still bound to the
     * declared related class.
     */
    public function testGetQueryPreservesBoundClass(): void
    {
        $user = TypeFlowUser::newQuery()->first();
        self::assertNotNull($user);

        $query = $user->posts()->getQuery();

        self::assertSame(TypeFlowPost::class, $query->modelClass);

        $filtered = $query->where('title', '=', 'two')->get();
        self::assertCount(1, $filtered);
        self::assertInstanceOf(TypeFlowPost::class, $filtered[0]);
        self::assertSame('two', $filtered[0]->title);
    }
}
