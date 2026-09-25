<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures;

/**
 * A fixture exercising the narrow-union argument contract.
 *
 * The class mixes natively-typed, docblock-only, and polymorphic
 * parameters so the rule's two halves can be asserted in isolation.
 *
 * @phpstan-type FixtureKey int|string|null|array
 */
final class NarrowUnionFixture
{
    /**
     * A docblock-only narrow union — the declaration check must flag it.
     *
     * @param  FixtureKey  $key
     * @return void
     */
    public function docblockOnlyKey(mixed $key): void
    {
    }

    /**
     * A natively-typed narrow union — the declaration check must pass it.
     *
     * @param  int|string|null|array  $key
     * @return void
     */
    public function nativeKey(int|string|null|array $key): void
    {
    }

    /**
     * A bare-mixed PHPDoc — the call-site check must pass it.
     *
     * @param  mixed  $value
     * @return void
     */
    public function polymorphic(mixed $value): void
    {
    }

    /**
     * A natively-typed narrow union — the call-site check must flag
     * arguments that can never satisfy it.
     *
     * @param  int|string|null|array  $key
     * @return void
     */
    public function nativeKeyCall(int|string|null|array $key): void
    {
    }

    /**
     * A shaped-array PHPDoc union — not natively expressible, so the
     * declaration check must pass it.
     *
     * @param  int|string|null|array<string, int|string|null>  $key
     * @return void
     */
    public function shapedKey(mixed $key): void
    {
    }
}
