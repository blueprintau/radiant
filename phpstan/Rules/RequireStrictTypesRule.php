<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Stmt\Declare_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires every analysed file to declare `strict_types=1`.
 *
 * PHP's type coercion is per-file: a call site in a non-strict file can
 * silently coerce arguments even when the callee is strict. Enforcing the
 * declaration on every file makes the whole package behave consistently.
 *
 * @implements Rule<FileNode>
 */
final class RequireStrictTypesRule implements Rule
{
    /**
     * The PhpParser node type this rule inspects.
     *
     * @return class-string<FileNode>
     */
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * Verify a file declares `strict_types=1`.
     *
     * @param FileNode $node The file's top-level statements.
     * @param Scope $scope The analysis scope (unused).
     * @return list<\PHPStan\Rules\IdentifierRuleError> An error when the
     *         declaration is missing, otherwise an empty list.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->getNodes() as $stmt) {
            if (!$stmt instanceof Declare_) {
                continue;
            }

            foreach ($stmt->declares as $declare) {
                if ($declare->key->toString() !== 'strict_types') {
                    continue;
                }

                if ($declare->value instanceof LNumber && $declare->value->value === 1) {
                    return [];
                }
            }
        }

        return [
            RuleErrorBuilder::message('File is missing declare(strict_types=1).')
                ->identifier('radiant.requireStrictTypes')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
