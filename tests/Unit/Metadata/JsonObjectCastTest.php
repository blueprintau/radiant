<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata;

use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\JsonObjectPost;
use BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures\UserPreferences;

/**
 * JsonStorable object property casting: an object round-trips through a
 * Json column via jsonSerialize() and static jsonDeserialize();
 * wrong-typed values and non-JsonStorable classes fail fast.
 */
final class JsonObjectCastTest extends DatabaseTestCase
{
    /**
     * Create the json_object_posts table from the model's attributes.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(JsonObjectPost::class);
    }

    /**
     * A JsonStorable object round-trips through a Json column.
     */
    public function testObjectRoundTripsThroughJsonColumn(): void
    {
        $post = new JsonObjectPost();
        $post->title = 'hello';
        $post->preferences = new UserPreferences(theme: 'light', locale: 'de');
        $post->save();

        $found = JsonObjectPost::find($post->id);

        self::assertNotNull($found);
        self::assertInstanceOf(UserPreferences::class, $found->preferences);
        self::assertSame('light', $found->preferences->theme);
        self::assertSame('de', $found->preferences->locale);
    }

    /**
     * The stored cell is the object's jsonSerialize() payload.
     */
    public function testStoredCellIsTheSerializedPayload(): void
    {
        $post = new JsonObjectPost();
        $post->title = 'raw';
        $post->preferences = new UserPreferences(theme: 'solarized');
        $post->save();

        $row = \BlueprintAU\Radiant\Database::table('json_object_posts')->first();

        self::assertNotNull($row);
        self::assertSame('{"theme":"solarized","locale":"en"}', $row->preferences);
    }

    /**
     * A null on a nullable object column round-trips as null.
     */
    public function testNullRoundTripsAsNull(): void
    {
        $post = new JsonObjectPost();
        $post->title = 'empty';
        $post->save();

        $found = JsonObjectPost::find($post->id);

        self::assertNotNull($found);
        self::assertNull($found->preferences);
    }

    /**
     * The write path rejects a value of the wrong object type.
     */
    public function testWrongObjectTypeOnEncodeThrows(): void
    {
        $metadata = MetadataFactory::for(JsonObjectPost::class);
        $mapping = $metadata->mappingFor('preferences');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column [preferences] expects a ' . UserPreferences::class . ' value; got stdClass.'
        );

        $mapping->column->encode(new \stdClass(), UserPreferences::class);
    }

    /**
     * A stored payload that decodes to a non-object fails fast with the
     * column named.
     */
    public function testNonObjectStoredValueFailsFast(): void
    {
        $post = new JsonObjectPost();
        $post->title = 'corrupt';
        $post->preferences = new UserPreferences();
        $post->save();

        \BlueprintAU\Radiant\Database::sqlConnection()->statement(
            'UPDATE json_object_posts SET preferences = ? WHERE id = ?',
            ['[1, 2, 3]', $post->id],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not decode to a JSON object');

        JsonObjectPost::find($post->id);
    }

    /**
     * A corrupt JSON cell fails fast with the column named.
     */
    public function testCorruptJsonCellFailsFast(): void
    {
        $post = new JsonObjectPost();
        $post->title = 'garbage';
        $post->preferences = new UserPreferences();
        $post->save();

        \BlueprintAU\Radiant\Database::sqlConnection()->statement(
            'UPDATE json_object_posts SET preferences = ? WHERE id = ?',
            ['{not json', $post->id],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('could not decode the value');

        JsonObjectPost::find($post->id);
    }

    /**
     * The metadata build accepts a JsonStorable class-string on a Json
     * column.
     */
    public function testMetadataAcceptsJsonSerializableClassString(): void
    {
        $metadata = MetadataFactory::for(JsonObjectPost::class);
        $mapping = $metadata->mappingFor('preferences');

        self::assertSame(ColumnType::Json, $mapping->column->type);
        self::assertSame(UserPreferences::class, $mapping->propertyType);
    }

    /**
     * A JsonSerializable class that is not JsonStorable fails fast at
     * decode time with the column named.
     */
    public function testMissingFromJsonFailsFastAtDecode(): void
    {
        $metadata = MetadataFactory::for(JsonObjectPost::class);
        $mapping = $metadata->mappingFor('preferences');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('does not implement JsonStorable');

        $mapping->column->decode('{"theme":"dark"}', NoFromJson::class);
    }
}

/**
 * A JsonSerializable class WITHOUT JsonStorable — the decode-time
 * fail-fast probe.
 */
class NoFromJson implements \JsonSerializable
{
    /**
     * The JSON payload for storage.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [];
    }
}
