<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\B2mPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\B2mTag;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmTag;

/**
 * The pivot `$table` argument accepts a model class-string and derives
 * the table name from the class's metadata — plus the construction-time
 * guards (non-model class-string, endpoint collision).
 */
final class PivotTableClassStringTest extends DatabaseTestCase
{
    /**
     * Create both fixture families' tables and the class-named pivots.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            Blueprint::fromMetadata(B2mPost::class),
            Blueprint::fromMetadata(B2mTag::class),
            Blueprint::fromMetadata(MtmPost::class),
            Blueprint::fromMetadata(MtmTag::class),
        );

        // The pivot-declaring models own no columns, so their tables are
        // built by hand — the class-string only supplies the NAME.
        $this->connection->create((new Blueprint('btm_join'))
            ->id()
            ->foreignId('b2m_posts_id', 'b2m_posts.id')
            ->foreignId('b2m_tags_id', 'b2m_tags.id'));
        $this->connection->create((new Blueprint('mtm_taggable'))
            ->foreignId('taggable_id', 'mtm_posts.id')
            ->column(ColumnType::String, 'taggable_type', length: 255)
            ->foreignId('mtm_tags_id', 'mtm_tags.id'));

        $this->connection->table('b2m_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('b2m_tags')->insert(['id' => 1, 'label' => 'php']);
        $this->connection->table('mtm_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('mtm_tags')->insert(['id' => 1, 'label' => 'php']);
    }

    /**
     * A belongsToMany built through a pivot CLASS joins, attaches, and
     * reads through the class's derived table name.
     */
    public function testBelongsToManyAcceptsPivotClassString(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $relation = $post->tagged();

        self::assertSame('btm_join', $relation->getPivotTable());

        $relation->attach(1);

        $tags = $post->tagged()->get();
        self::assertCount(1, $tags);
        self::assertSame('php', $tags->first()?->attribute('label'));

        $row = $this->connection->table('btm_join')->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->b2m_posts_id);
        self::assertSame(1, $row->b2m_tags_id);
    }

    /**
     * A morphToMany built through a pivot CLASS stamps the alias and
     * reads through the class's derived table name.
     */
    public function testMorphToManyAcceptsPivotClassString(): void
    {
        $post = MtmPost::newQuery()->find(1);
        self::assertNotNull($post);

        $relation = $post->tagged();

        self::assertSame('mtm_taggable', $relation->getPivotTable());

        $relation->attach(1);

        $tags = $post->tagged()->get();
        self::assertCount(1, $tags);
        self::assertSame('php', $tags->first()?->attribute('label'));

        $row = $this->connection->table('mtm_taggable')->first();
        self::assertNotNull($row);
        self::assertSame(MtmPost::class, $row->taggable_type);
    }

    /**
     * A backslashed string that resolves to no model fails fast at
     * construction — not as a SQL error on first read.
     */
    public function testNonModelClassStringThrowsAtConstruction(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('resolves to no model class');

        $post->belongsToManyRaw(\BlueprintAU\Radiant\Database\Query\QueryBuilder::class);
    }

    /**
     * A pivot class whose table collides with an endpoint's table is
     * rejected — the join would be ambiguous.
     */
    public function testPivotClassCollidingWithEndpointThrows(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('collides with the parent');

        $post->tagThrough(B2mTag::class);
    }

    /**
     * The plain-string pivot table form is untouched.
     */
    public function testPlainStringPivotTableUnchanged(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        self::assertSame(
            'b2m_posts_b2m_tags',
            $post->tags()->getPivotTable(),
        );
    }
}
