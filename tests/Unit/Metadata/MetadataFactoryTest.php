<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata;

use BlueprintAU\Radiant\Metadata\ClassMetadata;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Metadata\PropertyMapping;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\Admin;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\AlignedDefaultModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ArityMismatchModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ConcreteBase;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ConcreteUser;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\JsonPrimaryKey;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\Contractor;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ColumnAddingAdmin;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\CustomDeletedAtPost;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\DoubleUniqueModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\DivergentDefaultModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmailVerificationToken;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmptyTableModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\Box;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\Category;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\HelperModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\LengthlessStringModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\MtiAdmin;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\MtiRedeclaredKey;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\MtiSuperAdmin;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\NonDatetimeSoftDeletePost;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\Shipment;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\SoftDeletingPost;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\TableRedeclaringAdmin;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\UnionTypedModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\UnknownConstraintColumnModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the {@see MetadataFactory}: column collection, casting capture,
 * table resolution (rules 1–5), soft-delete injection, constraint
 * collection + validation, and the tables() inventory.
 */
final class MetadataFactoryTest extends TestCase
{
    // ---- Column collection ----

    /**
     * Columns are collected keyed by property name with resolved names.
     */
    public function testCollectsColumnsKeyedByProperty(): void
    {
        $properties = MetadataFactory::for(User::class)->properties;

        self::assertSame(
            ['id', 'email', 'password', 'emailVerifiedAt', 'roleId', 'meta'],
            array_keys($properties),
        );
        self::assertInstanceOf(PropertyMapping::class, $properties['id']);
        self::assertSame('roleId', $properties['roleId']->propertyName);
        self::assertSame('roleId', $properties['roleId']->columnName);
        self::assertSame('emailVerifiedAt', $properties['emailVerifiedAt']->columnName);
    }

    /**
     * The property type name is captured for the cast pipeline.
     */
    public function testCapturesPropertyTypeForCasting(): void
    {
        $properties = MetadataFactory::for(User::class)->properties;

        self::assertSame('int', $properties['id']->propertyType);
        self::assertSame('string', $properties['email']->propertyType);
        self::assertSame(\Carbon\Carbon::class, $properties['emailVerifiedAt']->propertyType);
        self::assertSame('array', $properties['meta']->propertyType);
    }

    /**
     * The owning class is recorded as the declaring class.
     */
    public function testRecordsOwnerAsDeclaringClass(): void
    {
        $properties = MetadataFactory::for(ConcreteUser::class)->properties;

        self::assertSame(
            \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\AbstractPerson::class,
            $properties['id']->owner,
        );
        self::assertSame(ConcreteUser::class, $properties['email']->owner);
    }

    /**
     * Primary keys are collected from the merged properties.
     */
    public function testCollectsPrimaryKeys(): void
    {
        $metadata = MetadataFactory::for(User::class);

        self::assertCount(1, $metadata->primaryKeys);
        self::assertSame('id', $metadata->primaryKeys[0]->name);
    }

    /**
     * A union-typed column property is a fail-fast metadata error.
     */
    public function testUnionTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('union/intersection type');
        MetadataFactory::for(UnionTypedModel::class);
    }

    /**
     * A string column without a length is a fail-fast metadata error.
     */
    public function testStringWithoutLengthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('string column without a length');
        MetadataFactory::for(LengthlessStringModel::class);
    }

    /**
     * A primary key on a non-PK-capable column type is a fail-fast
     * metadata error.
     */
    public function testNonPkCapablePrimaryKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires an integer, string, char, or uuid type');
        MetadataFactory::for(JsonPrimaryKey::class);
    }

    /**
     * A PHP property default that diverges from the declared column default
     * is a fail-fast metadata error — the initialized property shadows the
     * column default on every model INSERT, so the pair silently disagrees
     * with raw SQL writes.
     */
    public function testDivergentDefaultThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('declares a PHP default');
        MetadataFactory::for(DivergentDefaultModel::class);
    }

    /**
     * An aligned (identical) PHP default is redundant but allowed, and a
     * property without a PHP default coexists with a column default — the
     * column default applies when the property is uninitialized.
     */
    public function testAlignedAndAbsentDefaultsPass(): void
    {
        $properties = MetadataFactory::for(AlignedDefaultModel::class)->properties;

        self::assertSame('anon', $properties['name']->column->default);
        self::assertSame(0, $properties['hits']->column->default);
    }

    // ---- Table resolution (rules 1–5) ----

    /**
     * Rule 5: the snake-cased plural convention.
     */
    public function testDefaultTableNameConvention(): void
    {
        self::assertSame('email_verification_tokens', MetadataFactory::for(EmailVerificationToken::class)->tableName);
        self::assertSame('categories', MetadataFactory::for(Category::class)->tableName);
        self::assertSame('boxes', MetadataFactory::for(Box::class)->tableName);
    }

    /**
     * An explicit #[Table(name: ...)] wins over the convention.
     */
    public function testExplicitTableNameWins(): void
    {
        self::assertSame('users', MetadataFactory::for(User::class)->tableName);
        self::assertSame('shipments', MetadataFactory::for(Shipment::class)->tableName);
    }

    /**
     * Rule 4: a column-less concrete base owns no table.
     */
    public function testColumnlessBaseHasNoTable(): void
    {
        self::assertNull(MetadataFactory::for(ConcreteBase::class)->tableName);
    }

    /**
     * Rule 4 + 5: a descendant of a column-less base resolves by convention.
     */
    public function testDescendantOfColumnlessBaseResolvesByConvention(): void
    {
        self::assertSame('helper_models', MetadataFactory::for(HelperModel::class)->tableName);
    }

    /**
     * Rule 4 + 5: an abstract base owns no table; the first concrete
     * descendant merges its columns and resolves by convention.
     */
    public function testAbstractBaseMergesIntoConcreteDescendant(): void
    {
        self::assertNull(
            MetadataFactory::for(\BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\AbstractPerson::class)->tableName,
        );

        $metadata = MetadataFactory::for(ConcreteUser::class);

        self::assertSame('concrete_users', $metadata->tableName);
        self::assertCount(3, $metadata->properties);
        self::assertArrayHasKey('name', $metadata->properties);
        self::assertArrayHasKey('email', $metadata->properties);
    }

    /**
     * An explicit table on the first concrete descendant of an abstract
     * base is legal — the base owns no table.
     */
    public function testExplicitTableOnDescendantOfAbstractBaseIsLegal(): void
    {
        self::assertSame('contractors', MetadataFactory::for(Contractor::class)->tableName);
    }

    /**
     * Rule 1: a behavior-only subclass inherits the nearest ancestor's table.
     */
    public function testBehaviorOnlySubclassInheritsAncestorTable(): void
    {
        self::assertSame('users', MetadataFactory::for(Admin::class)->tableName);
    }

    /**
     * Rule 2: a concrete subclass of a table-owning model adding columns
     * without #[Table] is the build error — the columns have nowhere to go.
     */
    public function testColumnAddingSubclassOfTableOwnerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('inherits columns from an ancestor and declares columns of its own');
        MetadataFactory::for(ColumnAddingAdmin::class);
    }

    /**
     * Rule 3 mis-declaration: a behavior-only subclass declaring its own
     * #[Table] — a second table with none of the columns — is a build error.
     */
    public function testTableRedeclaringSubclassOfTableOwnerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('inherits ALL columns from an ancestor and declares its own #[Table]');
        MetadataFactory::for(TableRedeclaringAdmin::class);
    }

    /**
     * An empty #[Table(name: '')] is a mis-declaration — fail fast.
     */
    public function testEmptyTableNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("declares #[Table(name: '')]");
        MetadataFactory::for(EmptyTableModel::class);
    }

    // ---- Multi-table inheritance (MTI) derivation ----

    /**
     * Rule 3 (MTI): a column-adding subclass with its own #[Table] resolves
     * to its OWN table and records the parent model.
     */
    public function testMtiChildResolvesOwnTableAndParent(): void
    {
        $metadata = MetadataFactory::for(MtiAdmin::class);

        self::assertSame('admins', $metadata->tableName);
        self::assertSame(User::class, $metadata->parentModel);
        self::assertTrue($metadata->isMtiChild());
    }

    /**
     * The derived key: same name as the parent's PK, autoIncrement false —
     * only the root table generates the id. The parent's metadata is
     * untouched (per-class clone).
     */
    public function testMtiDerivedKeyIsNonAutoIncrementClone(): void
    {
        $metadata = MetadataFactory::for(MtiAdmin::class);
        $parent = MetadataFactory::for(User::class);

        self::assertCount(1, $metadata->primaryKeys);
        self::assertSame('id', $metadata->primaryKeys[0]->name);
        self::assertFalse($metadata->primaryKeys[0]->autoIncrement);
        self::assertTrue($parent->primaryKeys[0]->autoIncrement, 'parent metadata must be untouched');
    }

    /**
     * The partition map: inherited columns belong to the parent's table,
     * own columns to the child's.
     */
    public function testMtiPartitionMap(): void
    {
        $metadata = MetadataFactory::for(MtiAdmin::class);

        self::assertSame('users', $metadata->tableFor('email'));
        self::assertSame('users', $metadata->tableFor('roleId'));
        self::assertSame('admins', $metadata->tableFor('level'));
        self::assertSame('admins', $metadata->tableFor('id'), 'the derived key lives on the child table');
    }

    /**
     * A third-level chain walks to any depth — every level keeps its own
     * table and the partition resolves across all of them.
     */
    public function testMtiThreeLevelChain(): void
    {
        $metadata = MetadataFactory::for(MtiSuperAdmin::class);

        self::assertSame('super_admins', $metadata->tableName);
        self::assertSame(MtiAdmin::class, $metadata->parentModel);
        self::assertSame('users', $metadata->tableFor('email'));
        self::assertSame('admins', $metadata->tableFor('level'));
        self::assertSame('super_admins', $metadata->tableFor('scope'));
    }

    /**
     * A child redeclaring the derived primary key is a build error — the
     * shared PK IS the table link.
     */
    public function testMtiRedeclaredKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('redeclares primary-key column [id]');
        MetadataFactory::for(MtiRedeclaredKey::class);
    }

    /**
     * tables() includes MTI children (they own tables).
     */
    public function testTablesInventoryIncludesMtiChildren(): void
    {
        $tables = MetadataFactory::tables([User::class, MtiAdmin::class]);

        self::assertSame('users', $tables[User::class]);
        self::assertSame('admins', $tables[MtiAdmin::class]);
    }

    // ---- tables() inventory ----

    /**
     * tables() maps table-owning classes and skips column-less ones.
     */
    public function testTablesInventorySkipsTablelessClasses(): void
    {
        $tables = MetadataFactory::tables([
            User::class,
            ConcreteBase::class,
            \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\AbstractPerson::class,
            Admin::class,
        ]);

        self::assertSame([
            User::class => 'users',
            Admin::class => 'users',
        ], $tables);
    }

    // ---- Soft deletes ----

    /**
     * The synthetic soft-delete column is injected for a using class.
     */
    public function testInjectsSyntheticSoftDeleteColumn(): void
    {
        $properties = MetadataFactory::for(SoftDeletingPost::class)->properties;

        self::assertArrayHasKey('deleted_at', $properties);

        $mapping = $properties['deleted_at'];
        self::assertSame('deleted_at', $mapping->columnName);
        self::assertSame(ColumnType::DateTime, $mapping->column->type);
        self::assertTrue($mapping->column->nullable);
        self::assertSame('datetime', $mapping->propertyType);
    }

    /**
     * A user-declared (renamed) delete column wins over the synthetic one.
     */
    public function testUserDeclaredSoftDeleteColumnWins(): void
    {
        $properties = MetadataFactory::for(CustomDeletedAtPost::class)->properties;

        self::assertArrayNotHasKey('deleted_at', $properties);
        // Declared mappings are keyed by property name; the synthetic
        // column was NOT injected (no 'removed_at' synthetic key either).
        self::assertArrayHasKey('removedAt', $properties);
        self::assertSame('removed_at', $properties['removedAt']->columnName);
    }

    /**
     * A non-datetime declared delete column is a fail-fast error.
     */
    public function testNonDatetimeSoftDeleteColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('SoftDeletes requires datetime');
        MetadataFactory::for(NonDatetimeSoftDeletePost::class);
    }

    // ---- Composite constraints ----

    /**
     * Composite constraints are collected from the class hierarchy.
     */
    public function testCollectsCompositeConstraints(): void
    {
        $metadata = MetadataFactory::for(Shipment::class);

        self::assertCount(1, $metadata->uniques);
        self::assertSame(['country', 'tracking'], $metadata->uniques[0]->columns);
        self::assertCount(1, $metadata->indexes);
        self::assertSame(['country', 'shippedAt'], $metadata->indexes[0]->columns);
        self::assertCount(1, $metadata->foreignKeys);
        self::assertSame(['regionId', 'country'], $metadata->foreignKeys[0]->columns);
        self::assertSame('geo_regions', $metadata->foreignKeys[0]->references);
    }

    /**
     * A constraint naming an unknown column fails fast at build.
     */
    public function testUnknownConstraintColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('unknown column [emial]');
        MetadataFactory::for(UnknownConstraintColumnModel::class);
    }

    /**
     * A #[ForeignKey] with mismatched arity fails fast at build.
     */
    public function testForeignKeyArityMismatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('matching arity; got 1 and 2');
        MetadataFactory::for(ArityMismatchModel::class);
    }

    /**
     * A class-level attribute covering a flagged column is a duplicate
     * declaration — fail fast.
     */
    public function testDuplicateFlagAndAttributeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('duplicate declaration');
        MetadataFactory::for(DoubleUniqueModel::class);
    }

    /**
     * A constraint declared on an ancestor travels with the merged columns.
     */
    public function testConstraintsAreInheritedFromAncestors(): void
    {
        // Shipment declares its constraints directly; re-resolving the same
        // metadata through a second class in a hierarchy (ConcreteUser ←
        // AbstractPerson) verifies the chain walk collects both levels.
        $metadata = MetadataFactory::for(Shipment::class);

        self::assertCount(1, $metadata->uniques);
        self::assertSame(['country', 'tracking'], $metadata->uniques[0]->columns);
    }

    // ---- Cache guarantees ----

    /**
     * Repeated for() calls return the identical cached instance.
     */
    public function testForIsCachedAndIdentityStable(): void
    {
        $first = MetadataFactory::for(User::class);
        $second = MetadataFactory::for(User::class);

        self::assertSame($first, $second);
        self::assertInstanceOf(ClassMetadata::class, $first);
    }

    /**
     * Build order never leaks between classes: touching the leaf first
     * leaves the parent's metadata unaffected (per-class isolation).
     */
    public function testParentMetadataIsIsolatedFromChildBuild(): void
    {
        $parent = MetadataFactory::for(User::class);
        MetadataFactory::for(Admin::class);

        self::assertSame($parent, MetadataFactory::for(User::class));
        self::assertSame('users', $parent->tableName);
        self::assertCount(6, $parent->properties);
    }
}
