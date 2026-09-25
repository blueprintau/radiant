<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan;

use BlueprintAU\Radiant\PHPStan\Rules\DisallowNarrowUnionArgumentsRule;
use PHPStan\Testing\RuleTestCase;

/**
 * The narrow-union argument contract, asserted through the analyser.
 *
 * The declaration half flags `mixed`-native parameters whose PHPDoc union
 * PHP could express natively; the call-site half flags arguments that can
 * never satisfy a declared union. Both identifiers are exercised here.
 *
 * @extends RuleTestCase<DisallowNarrowUnionArgumentsRule>
 */
final class DisallowNarrowUnionArgumentsRuleTest extends RuleTestCase
{
    /**
     * The rule under test, wired with PHPStan's own doc-comment resolver.
     *
     * @return DisallowNarrowUnionArgumentsRule
     */
    public function getRule(): \PHPStan\Rules\Rule
    {
        return new DisallowNarrowUnionArgumentsRule(self::getContainer()->getByType(\PHPStan\Type\FileTypeMapper::class));
    }

    /**
     * A docblock-only narrow union is flagged; a natively-typed one and a
     * shaped (non-expressible) union are not.
     */
    public function testDeclarationHalf(): void
    {
        $file = __DIR__ . '/Fixtures/NarrowUnionFixture.php';

        $this->analyse([$file], [
            [
                'Parameter $key of method docblockOnlyKey() is natively mixed but its PHPDoc type (array|int|string|null) is a union PHP could declare natively — type the parameter natively so invalid arguments fail with a TypeError.',
                23,
            ],
        ]);
    }

    /**
     * An argument that can never satisfy a declared union is flagged; a
     * valid argument and a bare-mixed parameter are not.
     */
    public function testCallSiteHalf(): void
    {
        $file = __DIR__ . '/Fixtures/NarrowUnionCallSite.php';

        $this->analyse([$file], [
            [
                'Argument #1 (true) passed to method nativeKeyCall() can never satisfy the declared parameter type array|int|string|null.',
                32,
            ],
            [
                'Argument #1 (stdClass) passed to method nativeKeyCall() can never satisfy the declared parameter type array|int|string|null.',
                52,
            ],
        ]);
    }
}
