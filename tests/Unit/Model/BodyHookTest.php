<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Exceptions\WriteVetoException;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BodyHookGrandchild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BodyHookParent;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BodyHookPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BodyOrderChild;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\BodyScopePost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OrderParentPost;

/**
 * Class-body hook declarations — a model declaring `#[WriteHook]`,
 * `#[RowHook]`, and `#[ModelScope]` methods in its own body collects and
 * dispatches them like trait-declared ones: body-first precedence at the
 * same level, inheritance through the ancestor walk, and a build error
 * when body and trait declare the same path.
 */
final class BodyHookTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables from their attributes. The subclass
     * fixtures are NOT passed through createTables — they share their
     * parent's table, so creating them again would collide.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            BodyHookPost::class,
            OrderParentPost::class,
            BodyHookParent::class,
            BodyScopePost::class,
        );


        BodyHookPost::resetProbes();
        BodyHookParent::$hookRuns = 0;
        OrderParentPost::$hookOrder = [];
    }

    /**
     * Reset the metadata cache so later test classes rebuild fixtures
     * whose metadata this class may have poisoned.
     */
    protected function tearDown(): void
    {
        MetadataFactory::clear();

        parent::tearDown();
    }

    /**
     * A class-body bulk insert hook dispatches on builder inserts,
     * seeing the rows in the PHP value space — the same contract a trait
     * hook gets.
     */
    public function testBodyRowHookFiresOnBulkInsert(): void
    {
        BodyHookPost::newQuery()->insert([
            ['name' => 'ada'],
            ['name' => 'ben'],
        ]);

        self::assertCount(2, BodyHookPost::$seenInsertRows);
        self::assertSame('ada', BodyHookPost::$seenInsertRows[0]['name']);
        self::assertSame('ben', BodyHookPost::$seenInsertRows[1]['name']);
    }

    /**
     * A class-body instance WriteHook fires on the update path of save().
     */
    public function testBodyWriteHookFiresOnSave(): void
    {
        $model = new BodyHookPost();
        $model->name = 'ada';
        $model->save();

        self::assertFalse(BodyHookPost::$updateHookFired, 'the INSERT path has no update hook');

        $model->name = 'renamed';
        $model->save();

        self::assertTrue(BodyHookPost::$updateHookFired, 'the body update hook fires on the update path');
    }

    /**
     * Body hooks across LEVELS (parent + child declaring the same path)
     * are precedence, not a collision — and the child's (leaf) hook runs
     * first.
     */
    public function testChildBodyHookRunsBeforeParentBodyHook(): void
    {
        OrderParentPost::$hookOrder = [];

        BodyOrderChild::newQuery()->insert(['name' => 'ada']);

        self::assertSame(['child', 'parent'], OrderParentPost::$hookOrder);
    }

    /**
     * The inherited body hook reaches TWO levels down — the ancestor
     * walk collects parent-body hooks for grandchildren too.
     */
    public function testBodyHookInheritsTwoLevels(): void
    {
        BodyHookParent::$hookRuns = 0;

        BodyHookGrandchild::newQuery()->insert(['name' => 'ada']);

        self::assertSame(1, BodyHookParent::$hookRuns, 'the grandparent body hook fires on the grandchild insert');
    }

    /**
     * Body hooks at DIFFERENT levels (parent + child declaring the same
     * path) do not collide — precedence, not an error. (The collision
     * rule is only trait-vs-body at one level.)
     */
    public function testParentAndChildBodyHooksDoNotCollide(): void
    {
        $metadata = MetadataFactory::for(BodyOrderChild::class);

        $declaring = array_column($metadata->rowHooks, 'declaring');

        self::assertContains(BodyOrderChild::class, $declaring, 'the child body hook collects');
        self::assertContains(OrderParentPost::class, $declaring, 'the parent body hook inherits');
    }

    /**
     * A class-body `#[ModelScope]` applies to every query automatically.
     */
    public function testBodyScopeAppliesToQueries(): void
    {
        BodyScopePost::newQuery()->insert([
            ['status' => 'published'],
            ['status' => 'draft'],
        ]);

        $statuses = BodyScopePost::newQuery()
            ->orderBy('id')
            ->getRaw()
            ->pluck('status')
            ->all();

        self::assertSame(['published'], $statuses, 'the body scope filters to published only');
    }

    /**
     * withoutScope(model class) strips exactly the body-declared scope —
     * the declaring class-string is the marker.
     */
    public function testWithoutScopeStripsBodyScope(): void
    {
        BodyScopePost::newQuery()->insert([
            ['status' => 'published'],
            ['status' => 'draft'],
        ]);

        $statuses = BodyScopePost::newQuery()
            ->withoutScope(BodyScopePost::class)
            ->orderBy('id')
            ->getRaw()
            ->pluck('status')
            ->all();

        self::assertSame(['published', 'draft'], $statuses, 'stripping the body scope reveals the draft');
    }

    /**
     * withoutScopes() strips body scopes along with trait scopes.
     */
    public function testWithoutScopesStripsBodyScope(): void
    {
        BodyScopePost::newQuery()->insert([
            ['status' => 'published'],
            ['status' => 'draft'],
        ]);

        $statuses = BodyScopePost::newQuery()
            ->withoutScopes()
            ->orderBy('id')
            ->getRaw()
            ->pluck('status')
            ->all();

        self::assertSame(['published', 'draft'], $statuses);
    }

    /**
     * A false-returning class-body RowHook vetoes the batch — the veto
     * exception attributes the hook to the MODEL class.
     */
    public function testBodyVetoExceptionAttributesModelClass(): void
    {
        $model = new /** A body-vetoing anonymous subclass owning the hook. */ class extends BodyHookPost
        {
            /**
             * The vetoing bulk insert hook — returns false to veto.
             *
             * @param  array<string, mixed>  $rows  Unused.
             * @return bool
             */
            #[\BlueprintAU\Radiant\Attributes\RowHook(\BlueprintAU\Radiant\Attributes\Hook::Insert)]
            protected static function vetoInsertRows(array &$rows): bool
            {
                unset($rows);

                return false;
            }
        };

        $class = $model::class;

        $exception = Expectation::throws(
            fn () => $class::newQuery()->insert(['name' => 'ada']),
            WriteVetoException::class,
        );

        self::assertSame($class, $exception->trait, 'the body veto attributes the model class');
        self::assertSame('vetoInsertRows', $exception->method);
        self::assertSame([], $class::newQuery()->get()->all(), 'no row may survive a vetoed batch');
    }

    /**
     * The trait-vs-body collision is a build error naming the model.
     */
    public function testBodyTraitCollisionThrows(): void
    {
        $exception = Expectation::throws(
            fn () => MetadataFactory::for(
                \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\BodyTraitCollisionModel::class,
            ),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString(
            'declares the insert hook on both its class body',
            $exception->getMessage(),
        );
    }
}
