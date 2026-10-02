<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata;

use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\AncestorUniqueFlagModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ChildUniqueAttributeModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\CompositePkRootModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\DuplicateMorphsModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmptyCheckModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmptyFkColumnsModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmptyFkReferencesModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmptyMorphNameModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EmptyNamedTableWithColumns;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\FkToNoPkModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeBadElementModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeBadReturnModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeParameterizedModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeUnknownColumnModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\MtiChildOfCompositePk;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\NestedSoftDeleteModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\NestedTraitModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\NonDatetimeStampModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\TimestampOverrideModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\WriteHookDestroyNonVoidModel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\WriteHookStaticModel;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the MetadataFactory validation arms — trait-scope and
 * write-hook contracts, timestamp/morphs guards, constraint validation,
 * cross-inheritance flag duplicates and the MTI root-key requirement.
 */
final class MetadataFactoryValidationTest extends TestCase
{
    /**
     * Reset the metadata cache after every test — fixtures share the
     * process-wide cache.
     */
    protected function tearDown(): void
    {
        MetadataFactory::clear();

        parent::tearDown();
    }

    // ---- Cache ----

    /**
     * for() returns the same cached instance; clear(class) evicts ONLY
     * the named class; clear() wipes everything.
     */
    public function testCacheClearSemantics(): void
    {
        $first = MetadataFactory::for(NestedTraitModel::class);
        $other = MetadataFactory::for(AncestorUniqueFlagModel::class);

        self::assertSame($first, MetadataFactory::for(NestedTraitModel::class));

        // A targeted clear evicts the named class and leaves the rest.
        MetadataFactory::clear(NestedTraitModel::class);
        self::assertNotSame($first, MetadataFactory::for(NestedTraitModel::class));
        self::assertSame(
            $other,
            MetadataFactory::for(AncestorUniqueFlagModel::class),
            'a targeted clear must not evict other classes',
        );

        // A full clear evicts everything.
        MetadataFactory::clear();
        self::assertNotSame($other, MetadataFactory::for(AncestorUniqueFlagModel::class));
    }

    // ---- Trait scopes ----

    /**
     * A #[ModelScope] method with a parameter fails the static/zero-param
     * contract.
     */
    public function testModelScopeParameterizedThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '#[ModelScope] method ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeParameterizedTrait::class
            . '::activeWithParam] must be static and take no parameters.',
        );

        MetadataFactory::for(ModelScopeParameterizedModel::class);
    }

    /**
     * A #[ModelScope] method returning a non-array fails the return-type
     * contract.
     */
    public function testModelScopeBadReturnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '#[ModelScope] method ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeBadReturnTrait::class
            . '::activeBadReturn] must return an array of ScopeCondition instances.',
        );

        MetadataFactory::for(ModelScopeBadReturnModel::class);
    }

    /**
     * A #[ModelScope] method returning a non-ScopeCondition element fails
     * the element contract, naming the offending type.
     */
    public function testModelScopeBadElementThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '#[ModelScope] method ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeBadElementTrait::class
            . '::activeBadElement] must return an array of ScopeCondition instances; got string.',
        );

        MetadataFactory::for(ModelScopeBadElementModel::class);
    }

    /**
     * A #[ModelScope] over a column the model lacks fails the column
     * existence check.
     */
    public function testModelScopeUnknownColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '#[ModelScope] on ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\ModelScopeUnknownColumnTrait::class
            . '] declares the column [ghost_column], which does not exist on model ['
            . ModelScopeUnknownColumnModel::class . '].',
        );

        MetadataFactory::for(ModelScopeUnknownColumnModel::class);
    }

    // ---- Write hooks ----

    /**
     * A static #[WriteHook] method fails the instance-method contract.
     */
    public function testWriteHookStaticThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '#[WriteHook] method ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\WriteHookStaticTrait::class
            . '::onInsertStatic] must be an instance method.',
        );

        MetadataFactory::for(WriteHookStaticModel::class);
    }

    /**
     * A #[WriteHook(Hook::Destroy)] method with a non-void return fails —
     * the hard DELETE is unclaimable.
     */
    public function testWriteHookDestroyNonVoidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '#[WriteHook(Hook::Destroy)] method ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\WriteHookDestroyNonVoidTrait::class
            . '::onDestroyNonVoid] must return void',
        );

        MetadataFactory::for(WriteHookDestroyNonVoidModel::class);
    }

    // ---- Nested traits ----

    /**
     * A scope declared on a trait used BY a used trait is found — the
     * trait walk recurses through trait-uses-trait.
     */
    public function testScopeFoundThroughNestedTrait(): void
    {
        $metadata = MetadataFactory::for(NestedTraitModel::class);

        $conditions = $metadata->traitScopes;
        self::assertNotSame([], $conditions, 'the nested trait\'s scope must be collected');
    }

    /**
     * SoftDeletes used BY a used trait injects the synthetic deleted_at
     * column — the trait walk reaches indirect traits.
     */
    public function testSoftDeletesFoundThroughNestedTrait(): void
    {
        $metadata = MetadataFactory::for(NestedSoftDeleteModel::class);

        self::assertNotNull($metadata->softDeleteColumn);
    }

    // ---- Timestamps ----

    /**
     * Overriding createdAtColumn() to an undeclared column fails the
     * override contract.
     */
    public function testTimestampOverrideToUndeclaredColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . TimestampOverrideModel::class . '] overrides createdAtColumn() to [created]'
            . ' but declares no #[Column] with that name',
        );

        MetadataFactory::for(TimestampOverrideModel::class);
    }

    /**
     * A stamp column declared as a non-datetime type fails the stamp-type
     * contract.
     */
    public function testNonDatetimeStampThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . NonDatetimeStampModel::class . '] declares stamp column [created_at]'
            . ' as [int], but Timestamps requires datetime.',
        );

        MetadataFactory::for(NonDatetimeStampModel::class);
    }

    // ---- Morphs ----

    /**
     * #[Morphs] declared twice with the same name fails the uniqueness
     * contract.
     */
    public function testDuplicateMorphsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "Model [" . DuplicateMorphsModel::class . "] declares #[Morphs(name: 'tag')] twice;"
            . ' a morph name must be unique per class.',
        );

        MetadataFactory::for(DuplicateMorphsModel::class);
    }

    /**
     * #[Morphs] with an empty name fails the non-empty contract.
     */
    public function testEmptyMorphNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . EmptyMorphNameModel::class . '] declares #[Morphs] with an empty name;'
            . ' a morph pair requires a non-empty name.',
        );

        MetadataFactory::for(EmptyMorphNameModel::class);
    }

    // ---- Constraints ----

    /**
     * A #[ForeignKey] with an empty column list fails the arity contract.
     */
    public function testEmptyFkColumnsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . EmptyFkColumnsModel::class . '] declares a #[ForeignKey] with an empty column list;'
            . ' a foreign key requires at least one column.',
        );

        MetadataFactory::for(EmptyFkColumnsModel::class);
    }

    /**
     * A #[ForeignKey] with an empty references column list fails the
     * arity contract.
     */
    public function testEmptyFkReferencesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . EmptyFkReferencesModel::class . '] declares a #[ForeignKey] with an empty references'
            . ' column list; a foreign key requires at least one referenced column.',
        );

        MetadataFactory::for(EmptyFkReferencesModel::class);
    }

    /**
     * A #[ForeignKey] referencing a model with no primary key fails — the
     * reference resolves its columns from the target PK.
     */
    public function testFkToNoPkModelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . FkToNoPkModel::class . '] declares a #[ForeignKey] referencing model ['
            . \BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\NoPkTargetModel::class
            . '], which declares no primary key;',
        );

        MetadataFactory::for(FkToNoPkModel::class);
    }

    /**
     * A #[Check] with a whitespace-only expression fails the non-empty
     * contract.
     */
    public function testEmptyCheckExpressionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . EmptyCheckModel::class . '] declares a #[Check] with an empty expression;'
            . ' a CHECK constraint requires a non-empty predicate.',
        );

        MetadataFactory::for(EmptyCheckModel::class);
    }

    // ---- Cross-inheritance flag duplicates ----

    /**
     * A class-level #[Unique] over a column the ANCESTOR already flagged
     * unique fails — the duplicate is detected across the inheritance
     * chain.
     */
    public function testAncestorChainFlagDuplicateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . ChildUniqueAttributeModel::class . '] declares a class-level unique constraint'
            . ' on column [email], but ancestor [' . AncestorUniqueFlagModel::class . ']'
            . ' already declares the same column with `unique: true`',
        );

        MetadataFactory::for(ChildUniqueAttributeModel::class);
    }

    // ---- MTI root key ----

    /**
     * An MTI child extending a composite-PK root fails — multi-table
     * inheritance requires a single named root key.
     */
    public function testMtiChildOfCompositePkRootThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . MtiChildOfCompositePk::class . '] extends table-owning ['
            . CompositePkRootModel::class . "], whose primary key is composite or unnamed.",
        );

        MetadataFactory::for(MtiChildOfCompositePk::class);
    }

    /**
     * #[Table(name: '')] on a class WITH own columns fails fast — the
     * empty-name mis-declaration.
     */
    public function testEmptyTableNameWithOwnColumnsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "Model [" . EmptyNamedTableWithColumns::class . "] declares #[Table(name: '')]",
        );

        MetadataFactory::for(EmptyNamedTableWithColumns::class);
    }
}
