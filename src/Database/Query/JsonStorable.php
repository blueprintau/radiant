<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * Value objects storable in a Json column — implement this interface so a
 * `#[Column(type: ColumnType::Json)]` property can declare the object type
 * directly.
 *
 * The write path encodes via `jsonSerialize()`; the read path hydrates via
 * `jsonDeserialize()`. Property access on the stdClass payload fails fast
 * on missing or misspelled keys — an array payload would silently null.
 */
interface JsonStorable extends \JsonSerializable
{
    /**
     * Reconstitute the object from its stored JSON payload.
     *
     * @param  \stdClass  $payload
     * @return static
     */
    public static function jsonDeserialize(\stdClass $payload): static;
}
