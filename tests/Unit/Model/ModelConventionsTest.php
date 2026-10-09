<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MvAuthor;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MvNoPk;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MvPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MvTableless;

/**
 * Model conventions and static surface: the static filter forwarders
 * (firstOrFail/sole/whereNested/limit/offset/groupBy/having) and the
 * fail-fast metadata guards (table-less model, unnamed PK, morph key
 * conventions). The FK-derivation defaults run inside relation factories
 * — covered implicitly by the CamelCase relation test at the bottom.
 */
final class ModelConventionsTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed one author.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(MvAuthor::class, MvPost::class);

        $author = new MvAuthor();
        $author->name = 'alicia';
        $author->save();
    }

    // ---- Static forwarders ----

    /**
     * Static firstOrFail() returns the row when one exists.
     */
    public function testStaticFirstOrDefault(): void
    {
        $author = MvAuthor::firstOrFail();

        self::assertSame('alicia', $author->name);
    }

    /**
     * Static firstOrFail() throws when the table is empty.
     */
    public function testStaticFirstOrDefaultThrowsWhenEmpty(): void
    {
        MvAuthor::newQuery()->delete();

        $this->expectException(\BlueprintAU\Radiant\Exceptions\ModelNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('No query results for model');

        MvAuthor::firstOrFail();
    }

    /**
     * Static sole() returns the only row; with two rows it throws
     * MultipleRecordsFoundException.
     */
    public function testStaticSole(): void
    {
        $sole = MvAuthor::sole();
        self::assertSame('alicia', $sole->name);

        $second = new MvAuthor();
        $second->name = 'ben';
        $second->save();

        $this->expectException(\BlueprintAU\Radiant\Exceptions\MultipleRecordsFoundException::class);
        $this->expectExceptionMessageIsOrContains('expected exactly 1');

        MvAuthor::sole();
    }

    /**
     * Static whereNested() composes a nested group through the forwarder.
     */
    public function testStaticWhereNestedForwarder(): void
    {
        $rows = MvAuthor::whereNested(function (WhereBuilder $nested): WhereBuilder {
            return $nested->whereEq('name', 'alicia');
        })->get();

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('alicia', $rows[0]->name);
    }

    /**
     * Static limit()/offset() page through the forwarders.
     */
    public function testStaticLimitOffsetForwarders(): void
    {
        $second = new MvAuthor();
        $second->name = 'ben';
        $second->save();

        $rows = MvAuthor::orderBy('id')->offset(1)->limit(1)->get();

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('ben', $rows[0]->name);
    }

    /**
     * Static groupBy()/having() aggregate through the forwarders — the
     * typed Aggregate column validates its inner column through the model
     * allowlist.
     */
    public function testStaticGroupByHavingForwarders(): void
    {
        $second = new MvAuthor();
        $second->name = 'ben';
        $second->save();
        $third = new MvAuthor();
        $third->name = 'cara';
        $third->save();

        $rows = MvAuthor::groupBy('name')
            ->having(\BlueprintAU\Radiant\Database\Query\Aggregate::count('*'), '>', 1)
            ->get();

        // Every name is unique — no group survives the HAVING filter.
        self::assertSame([], $rows->all());
    }

    // ---- Fail-fast metadata guards ----

    /**
     * A table-less model (no columns, no ancestor table) rejects at
     * Model::table().
     */
    public function testTablelessModelThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . MvTableless::class . '] declares no columns of its own and resolves no '
            . 'table. Add #[Column] properties, or extend a table-owning model behavior-only '
            . '(no new columns, no #[Table]).',
        );

        MvTableless::table();
    }

    /**
     * A model with no PK at all rejects at relation derivation.
     */
    public function testNoPrimaryKeyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Relation endpoints require a primary key; model [' . MvNoPk::class . '] declares none.',
        );

        $instance = new MvNoPk();
        $instance->name = 'x';

        $this->invokeHasMany($instance);
    }

    /**
     * The CamelCase FK convention: a relation on `MvAuthor` derives
     * `mv_author_id` (camelCase short name → snake_case + _id) without any
     * explicit foreign key.
     */
    public function testCamelCaseForeignKeyConvention(): void
    {
        $author = MvAuthor::newQuery()->first();
        self::assertNotNull($author);

        $posts = $author->posts()->get();

        // The derived FK column exists on the related table (declared) and
        // matches nothing — the relation compiled and ran against it.
        self::assertCount(0, $posts);
    }

    /**
     * Invoke the protected hasMany() factory on an instance. The param is
     * untyped (Model is abstract at the call boundary and the fixtures are
     * valid Models) — the mixed-typed helper keeps PHPStan's already-narrow
     * guard quiet while the runtime throw is what the tests assert.
     *
     * @param  object  $instance
     * @return mixed
     */
    private function invokeHasMany(object $instance): mixed
    {
        $method = new \ReflectionMethod($instance, 'hasMany');

        return $method->invoke($instance, MvAuthor::class);
    }
}
