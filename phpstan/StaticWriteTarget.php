<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\StaticStateRule;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\TypeCombinator;

/**
 * Resolves the static property that a write expression changes, and names the function a node sits in.
 */
final class StaticWriteTarget
{
    private static ?Standard $printer = null;

    /**
     * The key of the function or method the scope is in: "Class::method", "function", or "{main}" for file
     * level code such as routes.php.
     *
     * @param Scope $scope
     *
     * @return string
     */
    public static function functionKey(Scope $scope): string
    {
        $function = $scope->getFunction();
        if ($function === null) {
            return '{main}';
        }

        $class = $scope->getClassReflection();
        if ($class !== null && $scope->isInClass()) {
            return $class->getName() . '::' . $function->getName();
        }

        return $function->getName();
    }

    /**
     * Whether the scope is one of the named methods of a plugin class, outside any closure in it.
     *
     * @param Scope        $scope
     * @param list<string> $names
     *
     * @return bool
     */
    private static function isPluginMethod(Scope $scope, array $names): bool
    {
        $class    = $scope->getClassReflection();
        $function = $scope->getFunction();

        return $class !== null
            && $function !== null
            && !$scope->isInAnonymousFunction()
            && in_array($function->getName(), $names, true)
            && $class->is('System\\Classes\\PluginBase');
    }

    /**
     * Whether the scope is a plugin's resetWorkerState() method (outside any closure in it), which Winter.Octane
     * calls at the start of every operation.
     *
     * @param Scope $scope
     *
     * @return bool
     */
    public static function isResetRoot(Scope $scope): bool
    {
        return self::isPluginMethod($scope, ['resetWorkerState']);
    }

    /**
     * Whether the file is test code, which never runs in a production worker.
     *
     * @param string $file
     *
     * @return bool
     */
    public static function isTestFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return str_contains($file, '/tests/') || str_ends_with($file, 'TestCase.php');
    }

    /**
     * Whether the scope is a plugin's register() or boot() method, which runs once when the worker starts.
     * A closure defined there (an event listener, a terminating callback) runs later, per request, so it does
     * not count.
     *
     * @param Scope $scope
     *
     * @return bool
     */
    public static function isWorkerStart(Scope $scope): bool
    {
        return self::isPluginMethod($scope, ['boot', 'register']);
    }

    /**
     * Whether the node writes to the variable or property it targets.
     *
     * @param Node $node
     *
     * @return bool
     *
     * @phpstan-assert-if-true Assign|AssignOp|AssignRef|PreInc|PostInc|PreDec|PostDec $node
     */
    public static function isWriteNode(Node $node): bool
    {
        return $node instanceof Assign
            || $node instanceof AssignOp
            || $node instanceof AssignRef
            || $node instanceof PreInc
            || $node instanceof PostInc
            || $node instanceof PreDec
            || $node instanceof PostDec;
    }

    /**
     * The array offsets a write target goes through, as printed code: `[$key]` for `self::$x[$key]`, an empty string
     * for the whole property, and `[]` for an append.
     *
     * @param Node $target
     *
     * @return string
     */
    public static function offsetPath(Node $target): string
    {
        if (!$target instanceof ArrayDimFetch) {
            return '';
        }

        self::$printer ??= new Standard();
        $path            = '';
        while ($target instanceof ArrayDimFetch) {
            $path   = '[' . ($target->dim === null ? '' : self::$printer->prettyPrintExpr($target->dim)) . ']' . $path;
            $target = $target->var;
        }

        return $path;
    }

    /**
     * The static property an assignment target writes to, directly or through array offsets and object
     * properties (`self::$cache[$key] = ...`, `static::$state->value = ...`).
     *
     * @param Node                $target
     * @param Scope               $scope
     * @param ReflectionProvider  $reflectionProvider
     *
     * A property counts as worker scoped when it has an attribute named WorkerScoped (any namespace, so a project or
     * Winter.Octane can declare its own), or the worker-scoped tag sits in its docblock or its class's docblock. A
     * WeakMap property also counts as worker scoped: its entries go away with the objects they are keyed by.
     *
     * @return array{class: string, property: string, trait: string|null, workerScoped: bool}|null
     */
    public static function resolve(Node $target, Scope $scope, ReflectionProvider $reflectionProvider): ?array
    {
        $target = self::root($target);

        if (
            !$target instanceof StaticPropertyFetch
            || !$target->class instanceof Name
            || !$target->name instanceof VarLikeIdentifier
        ) {
            return null;
        }

        $className = $scope->resolveName($target->class);
        if (!$reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $reflectionProvider->getClass($className);
        $propertyName    = $target->name->toString();
        if (!$classReflection->hasNativeProperty($propertyName)) {
            return null;
        }

        $property = $classReflection->getNativeProperty($propertyName);
        if (!$property->isStatic()) {
            return null;
        }

        $attributes = array_map(
            fn ($attribute): string => substr($attribute->getName(), (int) strrpos('\\' . $attribute->getName(), '\\')),
            $property->getAttributes()
        );
        $classDoc   = (string) $property->getDeclaringClass()->getResolvedPhpDoc()?->getPhpDocString();
        $classNames = TypeCombinator::removeNull($property->getNativeType())->getObjectClassNames();

        return [
            'class'        => $property->getDeclaringClass()->getName(),
            'property'     => $propertyName,
            'trait'        => $property->getDeclaringTrait()?->getName(),
            'workerScoped' => in_array(StaticStateRule::WORKER_SCOPED_ATTRIBUTE, $attributes, true)
                || str_contains((string) $property->getDocComment(), StaticStateRule::WORKER_SCOPED_TAG)
                || str_contains($classDoc, StaticStateRule::WORKER_SCOPED_TAG)
                || in_array('WeakMap', $classNames, true),
        ];
    }

    /**
     * The node a write target writes into, past any array offsets and object properties.
     *
     * @param Node $target
     *
     * @return Node
     */
    public static function root(Node $target): Node
    {
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            $target = $target->var;
        }

        return $target;
    }
}
