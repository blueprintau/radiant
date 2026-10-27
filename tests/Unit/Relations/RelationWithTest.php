<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser;

/**
 * with() on a relation: nested eager-load paths register at the call
 * (validated fail-fast) and apply when results are fetched — the fresh
 * read carries them on the query, the snapshot read applies them to the
 * cached models. with() is not a filter, so the snapshot stays servable.
 */
final class RelationWithTest extends DatabaseTestCase
{
    /**
     * Create the rel_users / rel_posts fixture tables from the models'
     * attributes.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(RelUser::class, RelPost::class);
    }

    /**
     * Seed one user with two posts.
     *
     * @return RelUser The persisted user.
     */
    private function seed(): RelUser
    {
        $user = new RelUser();
        $user->email = 'alicia@example.com';
        $user->save();

        foreach (['First' => 'published', 'Second' => 'draft'] as $title => $status) {
            $post = new RelPost();
            $post->authorId = $user->id;
            $post->title = $title;
            $post->status = $status;
            $post->save();
        }

        return $user;
    }

    /**
     * The fresh read hydrates each related model with the nested
     * relation loaded.
     */
    public function testWithLoadsNestedRelationsOnAFreshRead(): void
    {
        $user = $this->seed();

        $posts = $user->posts()->with('author')->get();

        self::assertCount(2, $posts);

        foreach ($posts as $post) {
            $author = $post->cachedRelation('author');

            self::assertInstanceOf(RelUser::class, $author);
            self::assertSame($user->id, $author->id);
        }
    }

    /**
     * Dot-notation paths nest: the loaded author carries its own eager
     * loads.
     */
    public function testWithAcceptsDotNotationPaths(): void
    {
        $user = $this->seed();

        $posts = $user->posts()->with('author.posts')->get();
        $first = $posts->first();

        self::assertNotNull($first);

        $author = $first->cachedRelation('author');

        self::assertInstanceOf(RelUser::class, $author);

        $authorPosts = $author->cachedRelation('posts');

        self::assertInstanceOf(Collection::class, $authorPosts);
        self::assertCount(2, $authorPosts);
    }

    /**
     * with() is not a filter: the eagerly-loaded snapshot still serves
     * the read, and the nested loads apply to the cached models.
     */
    public function testWithKeepsTheSnapshotServableAndAppliesLoads(): void
    {
        $user = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        // Delete the posts AFTER the parent's with('posts') — a snapshot
        // read cannot see the change, a fresh read can.
        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->delete();

        $first = $loaded->posts()->with('author')->first();

        self::assertNotNull($first, 'the snapshot serves the with() read despite the delete');
        self::assertSame('First', $first->title);

        $author = $first->cachedRelation('author');

        self::assertInstanceOf(RelUser::class, $author);
        self::assertSame($user->id, $author->id);

        self::assertNull($loaded->posts()->first(fresh: true), 'fresh: bypasses the snapshot to the emptied database');
    }

    /**
     * with() composes with the filters — a filtered read runs fresh with
     * both the constraint and the nested loads.
     */
    public function testWithComposesWithFilters(): void
    {
        $user = $this->seed();

        $posts = $user->posts()->where('status', '=', 'draft')->with('author')->get();

        self::assertCount(1, $posts);

        $post = $posts->first();
        self::assertNotNull($post);
        self::assertSame('Second', $post->title);

        $author = $post->cachedRelation('author');

        self::assertInstanceOf(RelUser::class, $author);
    }

    /**
     * with() is immutable: the loads register on the clone, and the
     * original relation's reads stay un-nested.
     */
    public function testWithLeavesTheOriginalRelationUnchanged(): void
    {
        $user = $this->seed();

        $relation = $user->posts();
        $relation->with('author');

        foreach ($relation->get() as $post) {
            self::assertNull($post->cachedRelation('author'), 'the original relation does not carry the nested load');
        }
    }

    /**
     * An unknown nested path fails fast at the with() call.
     */
    public function testWithRejectsUnknownRelations(): void
    {
        $user = $this->seed();

        Expectation::throwsWithMessage(
            fn () => $user->posts()->with('bogus'),
            \InvalidArgumentException::class,
            'Unknown relation [bogus]',
        );
    }
}
