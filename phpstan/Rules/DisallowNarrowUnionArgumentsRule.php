<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

/**
 * Enforces the narrow-union argument contract at both ends of a call.
 *
 * A parameter whose PHPDoc type is a union narrower than its native type
 * (the KeyValue pattern: native `mixed`, PHPDoc `int|string|null|array`)
 * is a contract the language cannot check. The rule restores the check:
 *
 * - On declarations: a `mixed`-native parameter with a resolvable PHPDoc
 *   union that PHP could express natively must be declared natively.
 * - On call sites: an argument whose inferred type can never satisfy the
 *   parameter's PHPDoc union is reported.
 *
 * @implements Rule<CallLike|ClassMethod>
 */
final class DisallowNarrowUnionArgumentsRule implements Rule
{
    /**
     * Create the rule with the doc-comment resolver PHPStan itself uses.
     *
     * @param FileTypeMapper $fileTypeMapper Resolves doc comments with
     *        phpstan-type alias expansion.
     */
    public function __construct(
        private FileTypeMapper $fileTypeMapper,
    ) {
    }

    /**
     * The PhpParser node types this rule inspects.
     *
     * @return class-string<Node>
     */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * Dispatch a node to the declaration or call-site check.
     *
     * @param Node $node The method declaration or call to check.
     * @param Scope $scope The analysis scope.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof ClassMethod) {
            return $this->checkDeclaration($node, $scope);
        }

        if ($node instanceof CallLike) {
            return $this->checkCall($node, $scope);
        }

        return [];
    }

    /**
     * Flag `mixed`-native parameters whose PHPDoc union PHP could express
     * natively.
     *
     * @param ClassMethod $method The method declaration to check.
     * @param Scope $scope The analysis scope.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found.
     */
    private function checkDeclaration(ClassMethod $method, Scope $scope): array
    {
        $doc = $method->getDocComment();

        if ($doc === null) {
            return [];
        }

        $errors = [];

        foreach ($method->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            // A native type already present means the contract is checked
            // by the language — nothing to enforce. `mixed` is a native
            // keyword, so the parser reports it as a type; treat it as the
            // untyped form it semantically is.
            if ($param->type !== null && !$this->isMixedType($param->type)) {
                continue;
            }

            $phpDocType = $this->phpDocParamType($doc->getText(), $param->var->name, $scope);

            if ($phpDocType === null || !$this->isNarrowUnion($phpDocType)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Parameter $%s of method %s() is natively mixed but its PHPDoc type (%s) is a union PHP could declare natively — type the parameter natively so invalid arguments fail with a TypeError.',
                $param->var->name,
                $method->name->toString(),
                $phpDocType->describe(VerbosityLevel::precise()),
            ))
                ->identifier('radiant.narrowUnionNeedsNativeType')
                ->line($param->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Flag call-site arguments that can never satisfy the callee's PHPDoc
     * parameter type.
     *
     * @param CallLike $call The method or static-method call.
     * @param Scope $scope The analysis scope.
     * @return list<\PHPStan\Rules\IdentifierRuleError> The errors found.
     */
    private function checkCall(CallLike $call, Scope $scope): array
    {
        $acceptor = $this->resolveAcceptor($call, $scope);

        if ($acceptor === null) {
            return [];
        }

        $errors = [];

        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                continue;
            }

            $parameter = $this->parameterForArg($acceptor, $arg, $index);

            if ($parameter === null) {
                continue;
            }

            $phpDocType = $parameter->getPhpDocType();

            // Only union-narrowed contracts are in scope — a bare-mixed
            // PHPDoc (the polymorphic where() value) is intentionally
            // unchecked.
            if (!$this->isNarrowUnion($phpDocType)) {
                continue;
            }

            $argType = $scope->getType($arg->value);

            if ($argType instanceof MixedType || $argType instanceof NeverType) {
                continue;
            }

            if (!$phpDocType->accepts($argType, true)->result->no()) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Argument #%d (%s) passed to %s can never satisfy the declared parameter type %s.',
                $index + 1,
                $argType->describe(VerbosityLevel::precise()),
                $this->describeCallee($call),
                $phpDocType->describe(VerbosityLevel::precise()),
            ))
                ->identifier('radiant.narrowUnionArgument')
                ->line($arg->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Resolve the parameters acceptor a call targets.
     *
     * @param CallLike $call The call to resolve.
     * @param Scope $scope The analysis scope.
     * @return ParametersAcceptor|null The resolved acceptor, or null when
     *         the callee cannot be resolved statically.
     */
    private function resolveAcceptor(CallLike $call, Scope $scope): ?ParametersAcceptor
    {
        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            $name = $call->name->toString();
            $receiver = $scope->getType($call->var);

            // Scalar receivers (a generic TKey, a bare int) have no method
            // surface — checking prevents a reflection fatal, not a false
            // negative, since a scalar can never satisfy a union contract.
            if (!$receiver->hasMethod($name)->yes()) {
                return null;
            }

            $method = $receiver->getMethod($name, $scope);

            return $this->singleVariant($method->getVariants());
        }

        if ($call instanceof StaticCall && $call->name instanceof Identifier && $call->class instanceof Name) {
            $className = $scope->resolveName($call->class);

            if (!class_exists($className) && !interface_exists($className)) {
                return null;
            }

            $method = $scope->getMethodReflection(new ObjectType($className), $call->name->toString());

            return $method === null ? null : $this->singleVariant($method->getVariants());
        }

        return null;
    }

    /**
     * The single parameters acceptor of a method, or null for genuinely
     * variadic signatures.
     *
     * @param list<ParametersAcceptor> $variants The method's variants.
     * @return ParametersAcceptor|null The single variant, or null.
     */
    private function singleVariant(array $variants): ?ParametersAcceptor
    {
        return count($variants) === 1 ? $variants[0] : null;
    }

    /**
     * The parameter an argument binds to, honouring named arguments.
     *
     * @param ParametersAcceptor $acceptor The callee's signature.
     * @param Arg $arg The argument node.
     * @param int $index The argument's positional index.
     * @return ExtendedParameterReflection|null The bound parameter, or null
     *         when it cannot be determined.
     */
    private function parameterForArg(ParametersAcceptor $acceptor, Arg $arg, int $index): ?ExtendedParameterReflection
    {
        $parameters = $acceptor->getParameters();

        if ($arg->name instanceof Identifier) {
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $arg->name->toString()) {
                    return $parameter instanceof ExtendedParameterReflection ? $parameter : null;
                }
            }

            return null;
        }

        $parameter = $parameters[$index] ?? null;

        return $parameter instanceof ExtendedParameterReflection ? $parameter : null;
    }

    /**
     * The resolved PHPDoc type of one parameter, expanded through
     * phpstan-type aliases.
     *
     * The class name carries the resolution context — PHPStan passes a
     * null function name for methods, so the class docblock's
     * `@phpstan-type` aliases merge into the method's tags.
     *
     * @param string $docComment The method's raw doc comment.
     * @param string $paramName The parameter name without the `$`.
     * @param Scope $scope The analysis scope.
     * @return Type|null The resolved type, or null when undocumented.
     */
    private function phpDocParamType(string $docComment, string $paramName, Scope $scope): ?Type
    {
        $resolved = $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            $scope->getClassReflection()?->getName(),
            null,
            null,
            $docComment,
        );

        $tag = $resolved->getParamTags()[$paramName] ?? null;

        return $tag === null ? null : $tag->getType();
    }

    /**
     * Whether a parameter type node is the `mixed` keyword.
     *
     * @param \PhpParser\Node\ComplexType|\PhpParser\Node\Identifier|\PhpParser\Node\Name $type The parameter's type node.
     * @return bool True when the node is the `mixed` keyword.
     */
    private function isMixedType(\PhpParser\Node\ComplexType|\PhpParser\Node\Identifier|\PhpParser\Node\Name $type): bool
    {
        return $type instanceof \PhpParser\Node\Identifier && strtolower($type->toString()) === 'mixed';
    }

    /**
     * Whether a type is a union PHP could declare natively — at least two
     * members, every one a built-in scalar, null, or a bare array.
     *
     * @param Type $type The resolved PHPDoc type.
     * @return bool True when the union is natively expressible.
     */
    private function isNarrowUnion(Type $type): bool
    {
        if (!$type instanceof UnionType) {
            return false;
        }

        $members = $type->getTypes();

        if (count($members) < 2) {
            return false;
        }

        foreach ($members as $member) {
            if (!$this->isNativeExpressible($member)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one union member maps to a native type keyword.
     *
     * @param Type $type The union member.
     * @return bool True when the member is int, string, float, bool, null,
     *         or a bare (unshaped) array.
     */
    private function isNativeExpressible(Type $type): bool
    {
        if ($type->isInteger()->yes() || $type->isString()->yes()
            || $type->isFloat()->yes() || $type->isBoolean()->yes()
            || $type->isNull()->yes()) {
            return true;
        }

        // A bare ArrayType (no key/value refinement) maps to native `array`.
        // Shaped arrays (array<string, int>) have no native keyword — a
        // union containing one stays docblock-only.
        if (!$type->isArray()->yes()) {
            return false;
        }

        $arrays = $type->getArrays();

        foreach ($arrays as $array) {
            if (!$array->getKeyType() instanceof MixedType
                || !$array->getItemType() instanceof MixedType) {
                return false;
            }
        }

        return true;
    }

    /**
     * A human-readable callee description for error messages.
     *
     * @param CallLike $call The call to describe.
     * @return string The description.
     */
    private function describeCallee(CallLike $call): string
    {
        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            return sprintf('method %s()', $call->name->toString());
        }

        if ($call instanceof StaticCall && $call->name instanceof Identifier) {
            return sprintf('static method %s()', $call->name->toString());
        }

        return 'this call';
    }
}
