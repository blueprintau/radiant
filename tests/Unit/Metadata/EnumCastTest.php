<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata;

use \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\EnumPost;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\IntLevel;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\StringStatus;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\UnitKind;

/**
 * PHP enum property casting: backed and unit enums round-trip through
 * the column cast engine; invalid stored values fail fast.
 */
final class EnumCastTest extends DatabaseTestCase
{
    /**
     * Create the enum_posts table from the model's attributes.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(EnumPost::class);
    }

    /**
     * A backed string enum round-trips through an Enum column.
     */
    public function testBackedStringEnumRoundTrip(): void
    {
        $post = new EnumPost();
        $post->status = StringStatus::Published;
        $post->level = IntLevel::High;
        $post->kind = UnitKind::Beta;
        $post->save();

        $found = EnumPost::find($post->id);

        self::assertNotNull($found);
        self::assertSame(StringStatus::Published, $found->status);
        self::assertSame(IntLevel::High, $found->level);
        self::assertSame(UnitKind::Beta, $found->kind);
    }

    /**
     * A backed int enum stores its backing value.
     */
    public function testBackedIntEnumStoresBackingValue(): void
    {
        $post = new EnumPost();
        $post->status = StringStatus::Draft;
        $post->level = IntLevel::Low;
        $post->kind = UnitKind::Alpha;
        $post->save();

        $row = \BlueprintAU\Radiant\Database::table('enum_posts')->first();

        self::assertNotNull($row);
        self::assertSame('draft', $row->status);
        self::assertSame(1, $row->level);
        self::assertSame('Alpha', $row->kind);
    }

    /**
     * A stored value matching no enum case fails fast with the column
     * named.
     */
    public function testInvalidStoredValueFailsFast(): void
    {
        $post = new EnumPost();
        $post->status = StringStatus::Draft;
        $post->level = IntLevel::Low;
        $post->kind = UnitKind::Alpha;
        $post->save();

        \BlueprintAU\Radiant\Database::sqlConnection()->statement(
            "UPDATE enum_posts SET status = 'archived' WHERE id = ?",
            [$post->id],
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "Column [status] holds the value ['archived'], which is not a case of the enum ["
            . StringStatus::class . '].',
        );
        EnumPost::find($post->id);
    }

    /**
     * The builder's write path rejects a non-enum value for an enum
     * property column.
     */
    public function testNonEnumValueOnEncodeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('expects an enum value of type [' . StringStatus::class . ']; got string');

        EnumPost::newQuery()->insert(['status' => 'bogus', 'level' => 1, 'kind' => 'Alpha']);
    }

    /**
     * The metadata build accepts enum property types on compatible
     * columns and rejects incompatible ones.
     */
    public function testEnumTypeCompatibility(): void
    {
        // The fixture itself proves the compatible combos build.
        $metadata = \BlueprintAU\Radiant\Metadata\MetadataFactory::for(EnumPost::class);
        self::assertSame('enum_posts', $metadata->tableName);

        // An int-backed enum on a string column is incompatible.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('cannot store the field type [' . IntLevel::class . ']');

        $probe = new /** @description A probe model declaring an incompatible enum/column combo. */ class extends \BlueprintAU\Radiant\Model {
            /**
             * The int-backed enum on a string column.
             *
             * @var IntLevel
             */
            #[\BlueprintAU\Radiant\Attributes\Column(
                type: ColumnType::String,
                length: 10,
            )]
            public IntLevel $level;
        };

        \BlueprintAU\Radiant\Metadata\MetadataFactory::for($probe::class);
    }
}
