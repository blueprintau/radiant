<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\DuplicateMorphs;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MorphComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MorphDeclared;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MorphReaction;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\ShortMorphType;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\WrongMorphType;

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
