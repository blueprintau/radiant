<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes;

use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\ReferenceResolver;
use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\RefRole;
use BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures\RefTableless;
use PHPUnit\Framework\TestCase;

/**
 * {@see ReferenceResolver::resolve()} — the one foreign-key resolution
 * rule, exercised over all four input shapes: plain table (pass-through),
 * existing model (resolved through metadata), nonexistent class (fail
 * fast), and a class that is not a model (fail fast). A table-less model
 * (no columns) fails fast too.
 */
final class ReferenceResolverTest extends TestCase
{
    /**
     * Restore the static facade to an empty manager after each test so
     * metadata registered through the manager never leaks.
     */
    protected function tearDown(): void
    {
        Database::setManager(new Database\DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
    }


    /**
     * A plain table name passes through untouched — it never reaches the
     * metadata layer.
     */
    public function testPlainTableNamePassesThrough(): void
    {
        self::assertSame('roles', ReferenceResolver::resolve('roles'));
        self::assertSame('schema.roles', ReferenceResolver::resolve('schema.roles'));
    }

    /**
     * A model class-string resolves to its declared table name.
     */
    public function testModelClassResolvesToTableName(): void
    {
        self::assertSame('ref_roles', ReferenceResolver::resolve(RefRole::class));
    }

    /**
     * A backslash-bearing reference that names no class fails fast — the
     * message does NOT claim the input looks like a model (any
     * backslash-bearing non-class lands here, model-shaped or not).
     */
    public function testNonexistentClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A foreign key references [App\\Does\\Not\\Exist], which is neither a table '
            . 'name nor an existing model class-string.',
        );

        ReferenceResolver::resolve('App\\Does\\Not\\Exist');
    }

    /**
     * An existing class that is not a Radiant model fails fast — a
     * foreign key must reference a table name or a model class-string.
     *
     * The disambiguation gate is "contains a backslash", and a
     * `::class` constant has NO leading backslash — so the non-model
     * shape only arises for the leading-backslash spelling a config
     * value would carry (e.g. `'\DateTimeImmutable'` from a YAML/XML
     * attribute). Feed exactly that shape.
     */
    public function testNonModelClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A foreign key references [\DateTimeImmutable], which is a class but not a '
            . 'Radiant model; a foreign key must reference a table name or a model class-string.',
        );

        ReferenceResolver::resolve('\\' . \DateTimeImmutable::class);
    }

    /**
     * A NON-model `::class` constant (no leading backslash) fails fast —
     * PHP's `::class` never emits a leading backslash, so without the
     * class_exists check in the no-backslash branch a global-namespace
     * class-string would masquerade as a table name and only fail later
     * with a confusing SQL error.
     */
    public function testNonModelClassConstantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A foreign key references [DateTimeImmutable], which is a class but not a '
            . 'Radiant model; a foreign key must reference a table name or a model class-string.',
        );

        ReferenceResolver::resolve(\DateTimeImmutable::class);
    }

    /**
     * A model with no columns owns no table — resolution fails fast
     * instead of silently producing a broken FK target.
     */
    public function testTablelessModelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A foreign key references model [' . RefTableless::class . '], which owns no table '
            . '(no columns); a foreign key must reference a table-owning model or a plain table name.',
        );

        ReferenceResolver::resolve(RefTableless::class);
    }

    /**
     * A model-class reference WITHOUT explicit columns derives them from
     * the target's primary keys — the is_a guard narrows the reference to
     * class-string<Model> and the metadata call maps the PK names.
     */
    public function testModelReferenceDerivesPrimaryKeyColumns(): void
    {
        $foreignKey = new ForeignKey(columns: ['roleId'], references: RefRole::class);

        self::assertSame('ref_roles', $foreignKey->resolvedReferences());
        self::assertSame(['id'], $foreignKey->resolvedReferencesColumns());
    }

    /**
     * A model-class reference WITH explicit columns returns them verbatim
     * — the derivation arm is skipped.
     */
    public function testModelReferenceWithExplicitColumnsPassesThrough(): void
    {
        $foreignKey = new ForeignKey(
            columns: ['roleId'],
            references: RefRole::class,
            referencesColumns: ['pk'],
        );

        self::assertSame(['pk'], $foreignKey->resolvedReferencesColumns());
    }

    /**
     * A plain-table reference without columns falls back to the `id` PK
     * convention.
     */
    public function testPlainTableReferenceDefaultsToIdColumn(): void
    {
        $foreignKey = new ForeignKey(columns: ['roleId'], references: 'roles');

        self::assertSame(['id'], $foreignKey->resolvedReferencesColumns());
    }
}
