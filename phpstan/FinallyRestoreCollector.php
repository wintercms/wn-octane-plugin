<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\StaticWriteTarget;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
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
 * one (`self::$held[$name] = true; ... finally { unset(self::$held[$name]); }`).
 *
 * @implements Collector<TryCatch, list<array{0: string, 1: string}>>
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
     * @return list<array{0: string, 1: string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node->finally === null) {
            return null;
        }

        $targets = [];
        $finder  = new NodeFinder();
        foreach ($finder->findInstanceOf($node->finally->stmts, Assign::class) as $assign) {
            $targets[] = $assign->var;
        }

        foreach ($finder->findInstanceOf($node->finally->stmts, Unset_::class) as $unset) {
            array_push($targets, ...$unset->vars);
        }

        $function = StaticWriteTarget::functionKey($scope);
        $restored = [];
        foreach ($targets as $target) {
            $path = StaticWriteTarget::offsetPath($target);
            if (str_contains($path, '[]')) {
                continue;
            }

            $resolved = StaticWriteTarget::resolve($target, $scope, $this->reflectionProvider);
            if ($resolved !== null) {
                $restored[] = [$function, $resolved['class'] . '::$' . $resolved['property'] . $path];
            }
        }

        return $restored === [] ? null : $restored;
    }
}
