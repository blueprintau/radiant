<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * A model with a class-level #[Morphs] pair — the synthetic-column path.
 */
#[Morphs(name: 'commentable')]
#[Table(name: 'morph_comments')]
class MorphComment extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $body;
}

/**
 * A model with a NULLABLE morph pair — the optional-relation shape.
 */
#[Morphs(name: 'reactable', nullable: true)]
#[Table(name: 'morph_reactions')]
class MorphReaction extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;
}

/**
 * A model declaring the morph columns ITSELF — the user-declaration-wins
 * precedence path.
 */
#[Morphs(name: 'taggable')]
#[Table(name: 'morph_declared')]
class MorphDeclared extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user-declared type column — wins over the synthetic injection.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, name: 'taggable_type')]
    public string $taggableType;

    /**
     * The user-declared key column — wins over the synthetic injection.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'taggable_id')]
    public int $taggableId;
}

/**
 * A metadata error fixture: the SAME morph name declared twice.
 */
#[Morphs(name: 'dupable')]
#[Morphs(name: 'dupable')]
#[Table(name: 'morph_dup')]
class DuplicateMorphs extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;
}

/**
 * A metadata error fixture: a declared type column with the WRONG type.
 */
#[Morphs(name: 'wrongable')]
#[Table(name: 'morph_wrong_type')]
class WrongMorphType extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * An int column where the morph TYPE column must be — a build error.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'wrongable_type')]
    public int $wrongableType;
}

/**
 * A metadata error fixture: a declared type column that is TOO SHORT for
 * a full class-string.
 */
#[Morphs(name: 'shortable')]
#[Table(name: 'morph_short_type')]
class ShortMorphType extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A string column too short for a class-string — a build error.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 8, name: 'shortable_type')]
    public string $shortableType;
}

/**
 * Phase A: the `#[Morphs]` attribute + `Blueprint::morphs()` — synthetic
 * column injection, DDL emission, and the fail-fast guards.
 */
final class MorphsAttributeTest extends DatabaseTestCase
{
    /**
     * The attribute injects the `{name}_type`/`{name}_id` synthetic pair
     * into the metadata — no PHP properties, correct types.
     */
    public function testMorphsInjectsSyntheticColumns(): void
    {
        $metadata = MetadataFactory::for(MorphComment::class);

        self::assertTrue($metadata->hasColumn('commentable_type'));
        self::assertTrue($metadata->hasColumn('commentable_id'));

        $type = $metadata->mappingFor('commentable_type');
        self::assertNull($type->property);
        self::assertSame(ColumnType::String, $type->column->type);
        self::assertSame(255, $type->column->length);
        self::assertFalse($type->column->nullable);

        $key = $metadata->mappingFor('commentable_id');
        self::assertNull($key->property);
        self::assertSame(ColumnType::BigInt, $key->column->type);
        self::assertFalse($key->column->nullable);
    }

    /**
     * The synthetic morph columns round-trip through the model's attribute
     * store — set, save, reload, read.
     */
    public function testMorphColumnsRoundTripThroughAttributeStore(): void
    {
        $this->connection->create(Blueprint::fromMetadata(MorphComment::class));

        $comment = new MorphComment();
        $comment->id = 1;
        $comment->body = 'hello';
        $comment->setAttribute('commentable_type', MorphComment::class);
        $comment->setAttribute('commentable_id', 7);
        $comment->save();

        $row = $this->connection->table('morph_comments')->where('id', '=', 1)->first();
        self::assertNotNull($row);
        self::assertSame(MorphComment::class, $row->commentable_type);
        self::assertSame(7, $row->commentable_id);

        $loaded = MorphComment::newQuery()->find(1);
        self::assertNotNull($loaded);
        self::assertSame(MorphComment::class, $loaded->attribute('commentable_type'));
        self::assertSame(7, $loaded->attribute('commentable_id'));
    }

    /**
     * A nullable morph pair emits nullable columns.
     */
    public function testNullableMorphsEmitNullableColumns(): void
    {
        $metadata = MetadataFactory::for(MorphReaction::class);

        self::assertTrue($metadata->mappingFor('reactable_type')->column->nullable);
        self::assertTrue($metadata->mappingFor('reactable_id')->column->nullable);
    }

    /**
     * A user-declared morph column WINS — the attribute validates its type
     * instead of shadowing it.
     */
    public function testDeclaredMorphColumnsWin(): void
    {
        $metadata = MetadataFactory::for(MorphDeclared::class);

        $type = $metadata->mappingFor('taggable_type');
        self::assertNotNull($type->property);
        self::assertSame('taggableType', $type->propertyName);

        $key = $metadata->mappingFor('taggable_id');
        self::assertNotNull($key->property);
        self::assertSame('taggableId', $key->propertyName);
    }

    /**
     * fromMetadata() and a hand-built morphs() call produce IDENTICAL DDL —
     * the one-emission-path guarantee.
     */
    public function testFromMetadataMatchesHandBuiltMorphs(): void
    {
        $fromMetadata = Blueprint::fromMetadata(MorphComment::class);

        $handBuilt = (new Blueprint('morph_comments'))
            ->id()
            ->string('body', 64)
            ->morphs('commentable');

        $grammar = $this->connection->schemaGrammar;

        self::assertSame(
            $grammar->compileCreate($fromMetadata),
            $grammar->compileCreate($handBuilt),
        );
    }

    /**
     * A duplicate morph name is a build error.
     */
    public function testDuplicateMorphNameFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("declares #[Morphs(name: 'dupable')] twice");

        MetadataFactory::for(DuplicateMorphs::class);
    }

    /**
     * A declared type column with a non-string type is a build error.
     */
    public function testWrongMorphTypeFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires string');

        MetadataFactory::for(WrongMorphType::class);
    }

    /**
     * A declared type column too short for a class-string is a build error.
     */
    public function testShortMorphTypeFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('length of at least 255');

        MetadataFactory::for(ShortMorphType::class);
    }

    /**
     * Blueprint::morphs() rejects an empty name.
     */
    public function testMorphsRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty name');

        (new Blueprint('t'))->morphs('');
    }
}
