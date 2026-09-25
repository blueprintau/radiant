<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures;

/**
 * Call-site fixtures for the narrow-union argument contract.
 *
 * Each call exercises one acceptance outcome against the fixture's
 * natively-typed narrow union and its bare-mixed parameter.
 */
final class NarrowUnionCallSite
{
    /**
     * A valid scalar argument — the call-site check must pass it.
     *
     * @return void
     */
    public function validScalar(): void
    {
        (new NarrowUnionFixture())->nativeKeyCall(42);
    }

    /**
     * A bool argument — the call-site check must flag it.
     *
     * @return void
     */
    public function invalidBool(): void
    {
        (new NarrowUnionFixture())->nativeKeyCall(true);
    }

    /**
     * A valid array argument — the call-site check must pass it.
     *
     * @return void
     */
    public function validArray(): void
    {
        (new NarrowUnionFixture())->nativeKeyCall(['id' => 1]);
    }

    /**
     * An object argument — the call-site check must flag it.
     *
     * @return void
     */
    public function invalidObject(): void
    {
        (new NarrowUnionFixture())->nativeKeyCall(new \stdClass());
    }

    /**
     * A bool argument to a bare-mixed parameter — the call-site check
     * must pass it (the polymorphic contract is intentionally unchecked).
     *
     * @return void
     */
    public function polymorphicBool(): void
    {
        (new NarrowUnionFixture())->polymorphic(true);
    }
}
