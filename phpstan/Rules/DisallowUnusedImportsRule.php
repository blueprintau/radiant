<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports `use` imports that are never referenced in the file.
 *
 * An import counts as USED when any of the following resolves to it:
 *
 * - A `Name` node in code. PHPStan's parser runs the NameResolver, so
 *   every Name node is already fully qualified; `Scope::resolveName()`
 *   additionally maps `self`/`static`/`parent` to the current class.
 * - A class-like mention in a doc comment (`@param`, `@return`, `@var`,
 *   `@throws`, `@see`, inline `{@see ...}`, generic shapes like
 *   `Collection<Foo>`). Docblock names are resolved against the file's
 *   alias table, which this rule derives from the same `use` statements
 *   it audits — PHPStan 2.x does not expose the scope's NameScope.
 *
 * The doc-comment pass matters because a type that appears ONLY in a
 * docblock (e.g. a `@throws` for a documented exception) still needs its
 * import; a purely code-level scan would flag it as unused and invite
 * removal.
 *
 * @implements Rule<FileNode>
 */
final class DisallowUnusedImportsRule implements Rule
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
     * Collect the file's imports, find which are referenced, and report
     * the difference.
     *
     * @param FileNode $node The file's top-level statements.
     * @param Scope $scope The analysis scope, used to resolve code names.
     * @return list<\PHPStan\Rules\IdentifierRuleError> One error per
     *         unused import, otherwise an empty list.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $collected = $this->collectImports($node);
        $imports = $collected['imports'];

        if ($imports === []) {
            return [];
        }

        $used = $this->collectUsedNames($node, $collected['aliases'], $scope);

        $errors = [];

        foreach ($imports as $import) {
            if (isset($used[$import['fqn']])) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Import %s is never used; remove the use statement.',
                $import['display'],
            ))
                ->identifier('radiant.unusedImport')
                ->line($import['line'])
                ->build();
        }

        return $errors;
    }

    /**
     * Gather every `use` statement in the file, flattened to FQNs, plus
     * the alias table derived from them.
     *
     * Group uses (`use Foo\{Bar, Baz}`) and function/constant imports
     * (`use function`, `use const`) are expanded. Imports inside
     * `namespace` blocks are included, since a file may legally declare
     * several.
     *
     * @param FileNode $node The file node.
     * @return array{imports: list<array{fqn: string, display: string, line: int}>, aliases: array<string, string>, namespaces: list<string>}
     *         Each import's fully-qualified name, a human-readable form
     *         for error messages, and the line of the use statement; the
     *         alias => FQN map (lowercased alias keys, class imports
     *         only); and the declared namespace names.
     */
    private function collectImports(FileNode $node): array
    {
        $imports = [];
        $aliases = [];
        $namespaces = [];

        foreach ($node->getNodes() as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespaceName = $stmt->name?->toString() ?? '';
                $namespaces[] = $namespaceName;

                foreach ($stmt->stmts as $inner) {
                    $this->collectFromUseStatement($inner, $namespaceName, $imports, $aliases);
                }
                continue;
            }

            $this->collectFromUseStatement($stmt, '', $imports, $aliases);
        }

        return ['imports' => $imports, 'aliases' => $aliases, 'namespaces' => $namespaces];
    }

    /**
     * Expand a single `use` statement (or group use) into import entries
     * and alias-map rows.
     *
     * @param Node $stmt The statement to inspect; ignored when not a use.
     * @param string $namespace The enclosing namespace's name, or ''.
     * @param list<array{fqn: string, display: string, line: int}> $imports The import accumulator.
     * @param array<string, string> $aliases The alias accumulator (alias => FQN).
     */
    private function collectFromUseStatement(Node $stmt, string $namespace, array &$imports, array &$aliases): void
    {
        if (!$stmt instanceof Use_ && !$stmt instanceof GroupUse) {
            return;
        }

        $prefix = $stmt instanceof GroupUse ? $stmt->prefix->toString() : '';

        foreach ($stmt->uses as $use) {
            $localName = $use->name->toString();
            $fqn = $prefix === '' ? $localName : $prefix . '\\' . $localName;

            $alias = $use->getAlias()->toString();

            // Only class imports participate in the docblock alias table;
            // `use function`/`use const` aliases are not class names.
            if ($stmt->type === Use_::TYPE_NORMAL) {
                $aliases[strtolower($alias)] = $fqn;
            }

            $typeSuffix = match ($stmt->type) {
                Use_::TYPE_FUNCTION => ' function',
                Use_::TYPE_CONSTANT => ' const',
                default => '',
            };

            $imports[] = [
                'fqn' => $fqn,
                'display' => $alias . $typeSuffix,
                'line' => $stmt->getStartLine(),
            ];
        }
    }

    /**
     * Collect the FQNs referenced anywhere in the file: code names
     * resolved through the scope, plus identifiers extracted from doc
     * comments and resolved against the alias table.
     *
     * @param FileNode $node The file node.
     * @param array<string, string> $aliases The alias => FQN map from
     *        collectImports(), used to resolve docblock type names.
     * @param Scope $scope The analysis scope.
     * @return array<string, true> A set of used fully-qualified names.
     */
    private function collectUsedNames(FileNode $node, array $aliases, Scope $scope): array
    {
        $used = [];

        foreach ($this->allNodes($node) as $inner) {
            if ($inner instanceof Name) {
                $used[$scope->resolveName($inner)] = true;
                continue;
            }

            foreach ($this->docCommentTypes($inner) as $type) {
                $used[$this->resolveDocType($type, $aliases, $scope)] = true;
            }
        }

        return $used;
    }

    /**
     * Iterate every node in the file, including statements nested inside
     * namespace blocks.
     *
     * `use` statements are skipped entirely: their Name nodes are the
     * declarations being audited, not references, and PHPStan's parser
     * does not populate parent attributes so they cannot be filtered by
     * ancestry.
     *
     * @param FileNode $node The file node.
     * @return \Generator<Node>
     */
    private function allNodes(FileNode $node): \Generator
    {
        foreach ($node->getNodes() as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Use_ || $inner instanceof GroupUse) {
                        continue;
                    }
                    yield from $this->descend($inner);
                }
                continue;
            }

            if ($stmt instanceof Use_ || $stmt instanceof GroupUse) {
                continue;
            }

            yield from $this->descend($stmt);
        }
    }

    /**
     * Depth-first traversal of a node and its children.
     *
     * @param Node $node The node to traverse from.
     * @return \Generator<Node>
     */
    private function descend(Node $node): \Generator
    {
        yield $node;

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};

            if ($child instanceof Node) {
                yield from $this->descend($child);
            } elseif (is_array($child)) {
                foreach ($child as $grandchild) {
                    if ($grandchild instanceof Node) {
                        yield from $this->descend($grandchild);
                    }
                }
            }
        }
    }

    /**
     * Extract identifier-shaped tokens from a node's doc comment.
     *
     * Anything that looks like a type reference is returned: this is
     * deliberately permissive, because a false "used" verdict is harmless
     * (the import stays) while a false "unused" verdict would push the
     * developer to delete a needed import.
     *
     * @param Node $node The node whose doc comment to scan.
     * @return list<string> Raw (unresolved) type names from the docblock.
     */
    private function docCommentTypes(Node $node): array
    {
        $doc = $node->getDocComment();

        if ($doc === null) {
            return [];
        }

        // Match identifier chains with optional namespace separators and
        // a leading backslash. Tag names (`@param`, `@throws`) do not
        // match because `@` is not in the character class; even if a tag
        // name were matched it would resolve to nothing in the alias
        // table and only pollute the used-set harmlessly.
        preg_match_all('/[\\\\A-Za-z_][\\\\A-Za-z0-9_]*/', $doc->getText(), $matches);

        return $matches[0];
    }

    /**
     * Resolve a doc-comment type name to its FQN using the file's alias
     * table, mirroring PHP's own resolution rules (and PHPStan's
     * NameScope::resolveStringName).
     *
     * - Fully-qualified (`\Foo\Bar`) is used as-is.
     * - A name whose FIRST segment matches an import alias expands to
     *   that import (e.g. `Collection` with `use ...\Collection`).
     * - Otherwise the name is relative to the current namespace.
     *
     * @param string $type The raw docblock type token.
     * @param array<string, string> $aliases The alias => FQN map.
     * @param Scope $scope The analysis scope providing the namespace.
     * @return string The resolved FQN (or the raw token when it cannot
     *         be resolved — harmless, since it just won't match).
     */
    private function resolveDocType(string $type, array $aliases, Scope $scope): string
    {
        if ($type === '' || !preg_match('/^[\\\\A-Za-z_]/', $type)) {
            return $type;
        }

        if ($type[0] === '\\') {
            return ltrim($type, '\\');
        }

        $first = explode('\\', $type)[0];
        $aliasKey = strtolower($first);

        if (isset($aliases[$aliasKey])) {
            return $aliases[$aliasKey] . substr($type, strlen($first));
        }

        $namespace = $scope->getNamespace();

        return $namespace === null ? $type : $namespace . '\\' . $type;
    }
}
