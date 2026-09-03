<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires every analysed file to declare a namespace.
 *
 * Namespaces prevent class-name collisions between packages and make the
 * PSR-4 autoloading contract explicit. Every file in this package must be
 * namespaced under the package root.
 *
 * The rule verifies that the declared namespace matches the file's location
 * relative to its PSR-4 autoload root, so a file that passes the rule is
 * guaranteed to be autoloadable.
 *
 * @implements Rule<FileNode>
 */
final class RequireNamespaceRule implements Rule
{
    /**
     * @param array<string, string> $psr4Map PSR-4 prefix => directory pairs,
     *        e.g. ['BlueprintAU\\Radiant\\' => 'src/'].
     */
    public function __construct(
        private array $psr4Map = ['BlueprintAU\\Radiant\\' => 'src/'],
    ) {
    }

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
     * Verify a file declares a namespace matching its PSR-4 autoload root.
     *
     * @param FileNode $node The file's top-level statements.
     * @param Scope $scope The analysis scope.
     * @return list<\PHPStan\Rules\IdentifierRuleError> An error when the
     *         namespace is missing, outside the PSR-4 map, or mismatched with
     *         the file path, otherwise an empty list.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->getNodes() as $stmt) {
            if (!$stmt instanceof Namespace_) {
                continue;
            }

            $name = $stmt->name?->toString();

            if ($name === null) {
                return [
                    RuleErrorBuilder::message('File uses a bracketed/global namespace; declare a named namespace.')
                        ->identifier('radiant.missingNamespace')
                        ->line($stmt->getStartLine())
                        ->build(),
                ];
            }

            $expected = $this->expectedNamespace($scope->getFile());

            if ($expected === null) {
                return [
                    RuleErrorBuilder::message('File is not under any configured PSR-4 autoload root; it cannot be autoloaded.')
                        ->identifier('radiant.wrongNamespace')
                        ->line($stmt->getStartLine())
                        ->build(),
                ];
            }

            if ($name !== $expected) {
                return [
                    RuleErrorBuilder::message(sprintf(
                        'Namespace "%s" does not match the file path; expected "%s".',
                        $name,
                        $expected,
                    ))
                        ->identifier('radiant.namespacePathMismatch')
                        ->line($stmt->getStartLine())
                        ->build(),
                ];
            }

            return [];
        }

        return [
            RuleErrorBuilder::message('File is missing a namespace declaration.')
                ->identifier('radiant.missingNamespace')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * Compute the namespace a file must declare to be autoloadable under the
     * configured PSR-4 map.
     *
     * @param string $file The absolute path of the analysed file.
     * @return string|null The expected namespace, or null when the file is
     *         not under any mapped autoload root (in which case it cannot be
     *         autoloaded).
     */
    private function expectedNamespace(string $file): ?string
    {
        $file = $this->normalizePath($file);

        foreach ($this->psr4Map as $prefix => $dir) {
            $dir = rtrim($this->normalizePath($dir), DIRECTORY_SEPARATOR);

            if ($dir === '' || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($file, strlen($dir) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            // Strip the trailing filename to leave the namespace segments.
            $relative = substr($relative, 0, (int) strrpos($relative, '\\'));

            return rtrim($prefix, '\\') . ($relative === '' ? '' : '\\' . $relative);
        }

        return null;
    }

    /**
     * Normalize a path to an absolute, separator-normalized form.
     *
     * @param string $path The path to normalize.
     * @return string The normalized path.
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }
}
