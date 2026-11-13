<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\PureExpression;
use Winter\Octane\PHPStan\StaticWriteTarget;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Records which functions call which, so the rule can follow resetWorkerState() into the methods it calls and
 * tell which functions only ever run while the worker starts or under test.
 *
 * Each edge carries the caller's context: CONTEXT_RESET for a plugin's resetWorkerState() body, CONTEXT_START for a
 * plugin's register() or boot() body, CONTEXT_TEST for
 * test code, CONTEXT_CLOSURE for a closure (which may run later, per request, wherever it is defined), and
 * CONTEXT_NORMAL for everything else.
 *
 * A closure that runs at a request boundary counts as a reset: a listener for an Octane operation event
 * (RequestReceived, RequestTerminated, TaskReceived, ...) or a callback passed to terminating(). The static calls in
 * its body are recorded as calls from REQUEST_BOUNDARY, and the static properties it writes as callees prefixed
 * with WRITE_PREFIX.
 *
 * @implements Collector<Expr, list<array{0: string, 1: string, 2: string}>>
 */
final class CallEdgeCollector implements Collector
{
    public const CONTEXT_CLOSURE = 'closure';

    public const CONTEXT_NORMAL = 'normal';

    public const CONTEXT_RESET = 'reset';

    public const CONTEXT_START = 'start';

    public const CONTEXT_TEST = 'test';

    public const REQUEST_BOUNDARY = '{octane-request-boundary}';

    public const WRITE_PREFIX = 'write:';

    private const BOUNDARY_EVENTS = [
        'Laravel\\Octane\\Events\\RequestHandled',
        'Laravel\\Octane\\Events\\RequestReceived',
        'Laravel\\Octane\\Events\\RequestTerminated',
        'Laravel\\Octane\\Events\\TaskReceived',
        'Laravel\\Octane\\Events\\TaskTerminated',
        'Laravel\\Octane\\Events\\TickReceived',
        'Laravel\\Octane\\Events\\TickTerminated',
    ];

    /**
     * @param ReflectionProvider $reflectionProvider
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * The closure this call registers as a request boundary listener, if it registers one. A first-class callable
     * of a static method (`self::flush(...)`) counts too.
     *
     * @param Node  $node
     * @param Scope $scope
     *
     * @return Closure|ArrowFunction|StaticCall|null
     */
    private function boundaryListener(Node $node, Scope $scope): Closure|ArrowFunction|StaticCall|null
    {
        if (!($node instanceof StaticCall || $node instanceof MethodCall) || !$node->name instanceof Identifier) {
            return null;
        }

        $args = $node->getArgs();
        $name = $node->name->toLowerString();
        if ($name === 'terminating' && isset($args[0])) {
            $listener = $args[0]->value;
        } elseif ($name === 'listen' && isset($args[1])) {
            $event = $args[0]->value;
            if (
                !$event instanceof ClassConstFetch
                || !$event->class instanceof Name
                || !in_array($scope->resolveName($event->class), self::BOUNDARY_EVENTS, true)
            ) {
                return null;
            }

            $listener = $args[1]->value;
        } else {
            return null;
        }

        return $listener instanceof Closure
            || $listener instanceof ArrowFunction
            || ($listener instanceof StaticCall && $listener->isFirstClassCallable())
            ? $listener
            : null;
    }

    /**
     * The static calls and the static properties cleared (assigned a constant) in a request boundary listener's body.
     *
     * @param Closure|ArrowFunction|StaticCall $listener
     * @param Scope                            $scope
     *
     * @return list<string>
     */
    private function boundaryListenerCallees(Closure|ArrowFunction|StaticCall $listener, Scope $scope): array
    {
        $finder  = new NodeFinder();
        $callees = [];
        foreach ($finder->findInstanceOf($listener, StaticCall::class) as $call) {
            if ($call->class instanceof Name && $call->name instanceof Identifier) {
                $callees[] = $this->staticCallKey($call, $scope);
            }
        }

        foreach ($finder->findInstanceOf($listener, Assign::class) as $assign) {
            if (!PureExpression::isConstant($assign->expr) || StaticWriteTarget::offsetPath($assign->var) !== '') {
                continue;
            }

            $resolved = StaticWriteTarget::resolve($assign->var, $scope, $this->reflectionProvider);
            if ($resolved !== null) {
                $callees[] = self::WRITE_PREFIX . $resolved['class'] . '::$' . $resolved['property'];
            }
        }

        return array_values(array_filter($callees));
    }

    /**
     * The context of the code the scope is in.
     *
     * @param Scope $scope
     *
     * @return string
     */
    private function context(Scope $scope): string
    {
        if (StaticWriteTarget::isTestFile($scope->getFile())) {
            return self::CONTEXT_TEST;
        } elseif ($scope->isInAnonymousFunction()) {
            return self::CONTEXT_CLOSURE;
        } elseif (StaticWriteTarget::isResetRoot($scope)) {
            return self::CONTEXT_RESET;
        } elseif (StaticWriteTarget::isWorkerStart($scope)) {
            return self::CONTEXT_START;
        } else {
            return self::CONTEXT_NORMAL;
        }
    }

    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * The key of a method, named after the class that declares it.
     *
     * @param ClassReflection $classReflection
     * @param string          $method
     *
     * @return string|null
     */
    private function methodKey(ClassReflection $classReflection, string $method): ?string
    {
        if (!$classReflection->hasNativeMethod($method)) {
            return null;
        }

        $native = $classReflection->getNativeMethod($method);

        return $native->getDeclaringClass()->getName() . '::' . $native->getName();
    }

    /**
     * @param Node  $node
     * @param Scope $scope
     *
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $callees = [];

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $callees[] = $this->staticCallKey($node, $scope);
        } elseif (
            ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier
        ) {
            foreach ($scope->getType($node->var)->getObjectClassReflections() as $classReflection) {
                $callees[] = $this->methodKey($classReflection, $node->name->name);
            }
        } elseif ($node instanceof FuncCall && $node->name instanceof Name) {
            $callees[] = $this->reflectionProvider->resolveFunctionName($node->name, $scope);
        } else {
            return null;
        }

        $caller  = StaticWriteTarget::functionKey($scope);
        $context = $this->context($scope);
        $edges   = [];
        foreach (array_filter($callees) as $callee) {
            $edges[] = [$caller, $callee, $context];
        }

        $listener = $this->boundaryListener($node, $scope);
        if ($listener !== null) {
            foreach ($this->boundaryListenerCallees($listener, $scope) as $callee) {
                $edges[] = [self::REQUEST_BOUNDARY, $callee, self::CONTEXT_NORMAL];
            }
        }

        return $edges === [] ? null : $edges;
    }

    /**
     * The key of the method a static call resolves to.
     *
     * @param StaticCall $call
     * @param Scope      $scope
     *
     * @return string|null
     */
    private function staticCallKey(StaticCall $call, Scope $scope): ?string
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            return null;
        }

        $className = $scope->resolveName($call->class);
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        return $this->methodKey($this->reflectionProvider->getClass($className), $call->name->name);
    }
}
