<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Database\Query\JsonStorable;

/**
 * A JsonStorable value object for object-cast round-trip tests.
 */
final class UserPreferences implements JsonStorable
{
    /**
     * Create the preferences object.
     *
     * @param  string  $theme
     * @param  string  $locale
     */
    public function __construct(
        public readonly string $theme = 'dark',
        public readonly string $locale = 'en',
    ) {}

    /**
     * Reconstitute the object from its stored JSON payload.
     *
     * @param  \stdClass  $payload
     * @return static
     */
    public static function jsonDeserialize(\stdClass $payload): static
    {
        return new static(
            theme: is_string($payload->theme ?? null) ? $payload->theme : 'dark',
            locale: is_string($payload->locale ?? null) ? $payload->locale : 'en',
        );
    }

    /**
     * The JSON payload for storage.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['theme' => $this->theme, 'locale' => $this->locale];
    }
}
