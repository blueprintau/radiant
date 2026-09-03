<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires every class-like declaration, method, and property to carry a
 * doc comment that documents its types.
 *
 * - Class-like declarations (class, interface, trait, enum) must have a
 *   doc comment.
 * - Methods must have a doc comment with a `@param` tag for every parameter
 *   and a `@return` tag.
 * - Properties must have a doc comment with a `@var` tag.
 *
 * Constructors are exempt from the `@return` requirement (they return
 * nothing meaningful), but still need `@param` tags for their parameters.
 * Methods with a native `void` return type are likewise exempt from the
 * `@return` requirement — there is no meaningful type to document.
 *
 * @implements Rule<ClassLike>
 */
final class RequireDocCommentRule implements Rule
{
    /**
     * Parsed doc comments are cached by raw text — many nodes share the same
     * generic descriptions so this avoids re-tokenizing every declaration.
     *
     * @var array<string, \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode>
     */
    private array $cache = [];

    /**
     * The phpdoc-parser reused across all doc comment parses.
     *
     * @var PhpDocParser
     */
    private PhpDocParser $phpDocParser;

    /**
     * The phpdoc lexer reused across all doc comment parses.
     *
     * @var Lexer
     */
    private Lexer $phpDocLexer;

    /**
     * Create the rule with its own phpdoc-parser instance.
     *
     * The parser components are stateless beyond the shared config, so
     * building them once here and reusing them per node is safe and cheap.
     */
    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->phpDocLexer = new Lexer($config);
        $this->phpDocParser = new PhpDocParser(
            $config,
            new TypeParser($config, new ConstExprParser($config)),
            new ConstExprParser($config),
        );
    }

    /**
     * The PhpParser node type this rule inspects.
     *
     * @return class-string<ClassLike>
     */
    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /**
     * Check a class-like declaration and all of its members.
     *
     * @param ClassLike $node The class, interface, trait, or enum to check.
     * @param Scope $scope The analysis scope (unused).
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->checkClassLike($node));

        foreach ($node->getMethods() as $method) {
            $errors = array_merge($errors, $this->checkMethod($method));
        }

        foreach ($node->getProperties() as $property) {
            $errors = array_merge($errors, $this->checkProperty($property));
        }

        foreach ($node->getConstants() as $constant) {
            $errors = array_merge($errors, $this->checkConstant($constant));
        }

        return $errors;
    }

    /**
     * Check that a class-like declaration carries a doc comment.
     *
     * @param ClassLike $node The class, interface, trait, or enum to check.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found, if any.
     */
    private function checkClassLike(ClassLike $node): array
    {
        $doc = $node->getDocComment();
        if ($doc !== null) {
            return [];
        }

        $name = $node->name?->toString() ?? 'anonymous class';

        return [
            RuleErrorBuilder::message(sprintf('Class-like declaration "%s" is missing a doc comment.', $name))
                ->identifier('radiant.missingClassDocComment')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * Check that a method has a doc comment with a `@param` tag per
     * parameter and — unless it is a constructor or natively void — a
     * `@return` tag.
     *
     * @param ClassMethod $method The method to check.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found, if any.
     */
    private function checkMethod(ClassMethod $method): array
    {
        $errors = [];
        $name = $method->name->toString();

        $doc = $method->getDocComment();
        if ($doc === null) {
            $errors[] = RuleErrorBuilder::message(sprintf('Method "%s()" is missing a doc comment.', $name))
                ->identifier('radiant.missingMethodDocComment')
                ->line($method->getStartLine())
                ->build();

            return $errors;
        }

        $phpDoc = $this->resolve($doc);

        $paramNames = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $paramNames[] = $param->var->name;
            }
        }

        $documentedParams = [];
        foreach ($phpDoc->getParamTagValues() as $paramTag) {
            $documentedParams[] = ltrim($paramTag->parameterName, '$');
        }

        foreach ($paramNames as $paramName) {
            if (!in_array($paramName, $documentedParams, true)) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf('Method "%s()" parameter "$%s" is missing a @param tag.', $name, $paramName),
                )
                    ->identifier('radiant.missingParamDoc')
                    ->line($method->getStartLine())
                    ->build();
            }
        }

        // Constructors have no meaningful return value; skip the @return check.
        if ($name !== '__construct' && !$this->isVoidReturnType($method) && $phpDoc->getReturnTagValues() === []) {
            $errors[] = RuleErrorBuilder::message(sprintf('Method "%s()" is missing a @return tag.', $name))
                ->identifier('radiant.missingReturnDoc')
                ->line($method->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Whether the method's native return type is `void`.
     *
     * @param ClassMethod $method The method to inspect.
     * @return bool True when the method returns void.
     */
    private function isVoidReturnType(ClassMethod $method): bool
    {
        $returnType = $method->returnType;

        return $returnType instanceof Node\Identifier && $returnType->toString() === 'void';
    }

    /**
     * Check that a property has a doc comment with a `@var` tag.
     *
     * @param Property $property The property to check.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found, if any.
     */
    private function checkProperty(Property $property): array
    {
        $doc = $property->getDocComment();
        if ($doc === null) {
            return [
                RuleErrorBuilder::message('Property is missing a doc comment with a @var tag.')
                    ->identifier('radiant.missingPropertyDocComment')
                    ->line($property->getStartLine())
                    ->build(),
            ];
        }

        $phpDoc = $this->resolve($doc);
        if ($phpDoc->getVarTagValues() === []) {
            return [
                RuleErrorBuilder::message('Property doc comment is missing a @var tag.')
                    ->identifier('radiant.missingVarDoc')
                    ->line($property->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * Check that a class constant carries a doc comment.
     *
     * @param ClassConst $constant The constant to check.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found, if any.
     */
    private function checkConstant(ClassConst $constant): array
    {
        $doc = $constant->getDocComment();
        if ($doc === null) {
            return [
                RuleErrorBuilder::message('Class constant is missing a doc comment.')
                    ->identifier('radiant.missingConstantDocComment')
                    ->line($constant->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * Parse a doc comment into a PhpDocNode, memoising by raw text.
     *
     * @param Doc $doc The doc comment to parse.
     * @return \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode The parsed node.
     */
    private function resolve(Doc $doc): \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode
    {
        $text = $doc->getText();

        if (isset($this->cache[$text])) {
            return $this->cache[$text];
        }

        $tokens = new TokenIterator($this->phpDocLexer->tokenize($text));
        $node = $this->phpDocParser->parse($tokens);
        $tokens->consumeTokenType(Lexer::TOKEN_END);

        return $this->cache[$text] = $node;
    }
}
