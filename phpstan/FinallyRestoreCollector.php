<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\StaticWriteTarget;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Records static properties that a `finally` block reassigns or unsets, as a whole or at one array offset, keyed by
 * the function it sits in. A function that sets a static and puts it back in `finally` leaves nothing behind for the
 * next request, so the rule skips its writes to that property, or to that offset when the `finally` names the same
 * one (`self::$held[$name] = true; ... finally { unset(self::$held[$name]); }`). Only writes up to the end of the
 * try block count, and the finally block has to clear the target (assign a constant or unset it) or assign it a local
 * variable the function saved the property into (`$previous = self::$user;`, see StaticSaveCollector).
 *
 * @implements Collector<TryCatch, list<array{0: string, 1: string, 2: int, 3: string|null, 4: int, 5: int}>>
 */
final class FinallyRestoreCollector implements Collector
{
    /**
     * @param ReflectionProvider $reflectionProvider
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @param TryCatch $node
     * @param Scope $scope
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string|null, 4: int, 5: int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node->finally === null) {
            return null;
        }

        if ($node->stmts === []) {
            return null;
        }

        /*
         * Each target with the local variable it is restored from, or null when the finally block clears it (assigns
         * a constant or unsets it). Any other value, such as one the request computed, restores nothing.
         */
        $targets = [];
        $finder  = new NodeFinder();
        foreach ($finder->findInstanceOf($node->finally->stmts, Assign::class) as $assign) {
            if (PureExpression::isConstant($assign->expr)) {
                $targets[] = [$assign->var, null];
            } elseif ($assign->expr instanceof Variable && is_string($assign->expr->name)) {
                $targets[] = [$assign->var, $assign->expr->name];
            } else {
                // The block stores a new value instead of putting the old one back.
            }
        }

        foreach ($finder->findInstanceOf($node->finally->stmts, Unset_::class) as $unset) {
            foreach ($unset->vars as $var) {
                $targets[] = [$var, null];
            }
        }

        $function = StaticWriteTarget::functionKey($scope);
        $tryEnd       = $node->stmts[count($node->stmts) - 1]->getEndLine();
        $finallyStart = $node->finally->getStartLine();
        $finallyEnd   = $node->finally->getEndLine();
        $restored = [];
        foreach ($targets as [$target, $variable]) {
            $path = StaticWriteTarget::offsetPath($target);
            if (str_contains($path, '[]')) {
                continue;
            }

            $resolved = StaticWriteTarget::resolve($target, $scope, $this->reflectionProvider);
            if ($resolved !== null) {
                $key        = $resolved['class'] . '::$' . $resolved['property'];
                $restored[] = [$function, $key . $path, $tryEnd, $variable, $finallyStart, $finallyEnd];
            }
        }

        return $restored === [] ? null : $restored;
    }
}
