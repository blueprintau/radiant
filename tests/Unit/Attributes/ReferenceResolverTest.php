<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes;

use BlueprintAU\Radiant\Attributes\ReferenceResolver;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
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
}

/**
 * Fixture: a table-owning model referenced by class-string.
 */
#[Table(name: 'ref_roles')]
class RefRole extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The role's label.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $label;
}

/**
 * Fixture: a behavior-only model with no columns — owns no table.
 */
class RefTableless extends Model
{
}
