<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures\TypeFlowPost;
use BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures\TypeFlowUser;

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
final class GenericTypeFlowTest extends DatabaseTestCase
{
    /**
     * Create the type_flow_users / type_flow_posts fixture tables from
     * the models' attributes and seed rows.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(TypeFlowUser::class, TypeFlowPost::class);

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
        self::assertNotNull($posts[0]);
        self::assertNotNull($posts[1]);
        self::assertSame('one', $posts[0]->title);
        self::assertSame('two', $posts[1]->title);
    }

    /**
     * The eager loader delivers the declared related type onto the parent —
     * read back through the typed relation method (the cache-backed path).
     */
    public function testEagerLoadDeliversDeclaredRelatedType(): void
    {
        $user = TypeFlowUser::with('posts')->first();
        self::assertNotNull($user);

        $posts = $user->posts()->getResults();
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
