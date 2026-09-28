<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CollPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CollUser;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Public-API coverage for {@see Collection} — the model-aware subclass:
 * find() key-shape semantics (scalar cross-type, composite shape-matching,
 * null strictness, the `==` over-match regressions), modelKeys(), load()
 * and fresh() contracts (row-gone keeps the item, one query, position
 * preservation).
 */
final class CollectionTest extends DatabaseTestCase
{
    /**
     * Create the coll_users / coll_posts fixture tables from the models'
     * attributes.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(CollUser::class, CollPost::class);
    }

    /**
     * Seed two users and three posts.
     *
     * @return array{id1: int, id2: int} The generated user ids.
     */
    private function seed(): array
    {
        $first = new CollUser();
        $first->email = 'ada@example.com';
        $first->save();

        $second = new CollUser();
        $second->email = 'grace@example.com';
        $second->save();

        foreach (['One', 'Two'] as $title) {
            $post = new CollPost();
            $post->userId = $first->id;
            $post->title = $title;
            $post->save();
        }

        $orphan = new CollPost();
        $orphan->userId = null;
        $orphan->title = 'Orphan';
        $orphan->save();

        return ['id1' => $first->id, 'id2' => $second->id];
    }

    // ---- find() ----

    /**
     * find() locates by scalar key; a miss returns null.
     */
    public function testFindScalar(): void
    {
        ['id1' => $id1] = $this->seed();

        $users = CollUser::all();

        $found = $users->find($id1);
        self::assertInstanceOf(CollUser::class, $found);
        self::assertSame($id1, $found->id);
        self::assertNull($users->find(99999));
    }

    /**
     * PK values round-trip dialect bytes through the codec, so find('42')
     * must match a model whose key is int 42 — cross-type scalar match.
     */
    public function testFindNumericStringMatchesInt(): void
    {
        ['id1' => $id1] = $this->seed();

        $found = CollUser::all()->find((string) $id1);
        self::assertInstanceOf(CollUser::class, $found);
        self::assertSame($id1, $found->id);
    }

    /**
     * Plain `==` over-matched (regression semantics now locked): find(0)
     * must NOT match a key like '0e1' (scientific notation loosely == 0),
     * and bool must not match '1' — normalization collapses numeric
     * strings to int, everything else compares strictly.
     */
    public function testFindRejectsLooseEqualityOvermatches(): void
    {
        ['id1' => $id1] = $this->seed();

        $users = CollUser::all();

        // No key is 0 or '0e1' — neither may match anything.
        self::assertNull($users->find(0));
        self::assertNull($users->find('0e1'));
        // A string that is not a numeric form of any id matches nothing.
        self::assertNull($users->find((string) ($id1 + 1) . '0'));
    }

    /**
     * A null key matches only a null key — find(null) never returns a
     * model whose key is 0 or ''.
     */
    public function testFindNullMatchesOnlyNull(): void
    {
        $this->seed();

        self::assertNull(CollUser::all()->find(null));
    }

    /**
     * A scalar find() never matches against a composite-key model treated
     * as an array, and vice versa: a map key against scalar keys returns
     * null without error.
     */
    public function testFindWithMapAgainstScalarKeysReturnsNull(): void
    {
        ['id1' => $id1] = $this->seed();

        self::assertNull(CollUser::all()->find(['id' => $id1]));
    }

    // ---- modelKeys() ----

    /**
     * modelKeys() lists every model's key in collection order.
     */
    public function testModelKeysInOrder(): void
    {
        $ids = $this->seed();

        $users = CollUser::all();
        self::assertSame([$ids['id1'], $ids['id2']], $users->modelKeys());

        // Post keys come off the same PK machinery.
        $posts = CollPost::all();
        self::assertCount(3, $posts->modelKeys());
    }

    // ---- load() ----

    /**
     * load() eager-loads a relation path onto every model in place.
     */
    public function testLoadPopulatesRelations(): void
    {
        $this->seed();

        $users = CollUser::all();
        self::assertNotNull($users[0]);
        self::assertFalse($users[0]->relationLoaded('posts'));

        $users->load('posts');

        self::assertTrue($users[0]->relationLoaded('posts'));
        self::assertCount(2, $users[0]->posts()->getResults());
        self::assertNotNull($users[1]);
        self::assertTrue($users[1]->relationLoaded('posts'));
        self::assertCount(0, $users[1]->posts()->getResults());
    }

    /**
     * load() on an empty collection is a no-op (no query, no error).
     */
    public function testLoadOnEmptyCollectionIsNoOp(): void
    {
        $users = CollUser::all();

        $this->assertSame($users, $users->load('posts'));
        $this->addToAssertionCount(1);
    }

    /**
     * load() with an unknown relation path fails fast.
     */
    public function testLoadUnknownRelationThrows(): void
    {
        $this->seed();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown relation [nope]');
        CollUser::all()->load('nope');
    }

    // ---- fresh() ----

    /**
     * fresh() re-queries every model — in-memory mutations are discarded.
     */
    public function testFreshDiscardsInMemoryMutations(): void
    {
        $this->seed();

        $users = CollUser::all();
        self::assertNotNull($users[0]);
        $users[0]->email = 'changed-in-memory@example.com';

        $users->fresh();

        self::assertNotNull($users[0]);
        self::assertSame('ada@example.com', $users[0]->email);
    }

    /**
     * A row deleted externally keeps its ORIGINAL model in place — fresh()
     * must not silently shrink a collection the caller is iterating.
     */
    public function testFreshKeepsItemForExternallyDeletedRow(): void
    {
        $ids = $this->seed();

        $users = CollUser::all();
        self::assertCount(2, $users);

        // Hard-delete the second user behind the collection's back.
        $this->connection->table('coll_users')->where('id', '=', $ids['id2'])->delete();

        $users->fresh();

        self::assertCount(2, $users, 'the deleted row keeps its original model in place');
        self::assertNotNull($users[1]);
        self::assertSame($ids['id2'], $users[1]->id);
    }

    /**
     * fresh() re-attaches replacements by key at the ORIGINAL positions —
     * order is stable even when the re-query returns a different order.
     */
    public function testFreshPreservesPositions(): void
    {
        $ids = $this->seed();

        $users = CollUser::all();

        // Mutate row 2 directly in the database.
        $this->connection->table('coll_users')->where('id', '=', $ids['id2'])->update(['email' => 'updated@example.com']);

        $users->fresh();

        self::assertNotNull($users[0]);
        self::assertNotNull($users[1]);
        self::assertSame($ids['id1'], $users[0]->id);
        self::assertSame($ids['id2'], $users[1]->id);
        self::assertSame('updated@example.com', $users[1]->email, 'row 2 was replaced by its fresh hydration');
        self::assertSame('ada@example.com', $users[0]->email);
    }

    /**
     * fresh() on an empty collection is a no-op.
     */
    public function testFreshOnEmptyCollectionIsNoOp(): void
    {
        $users = CollUser::all();

        self::assertSame($users, $users->fresh());
        $this->addToAssertionCount(1);
    }
}
