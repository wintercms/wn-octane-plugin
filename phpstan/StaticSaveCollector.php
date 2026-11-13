<?php

namespace Winter\Octane\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Records a static property saved into a local variable (`$previous = self::$user;`), keyed by the function it sits
 * in, so the rule can tell that `finally { self::$user = $previous; }` puts the old value back.
 *
 * @implements Collector<Assign, array{0: string, 1: string, 2: string}>
 */
final class StaticSaveCollector implements Collector
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
        return Assign::class;
    }

    /**
     * @param Assign $node
     * @param Scope  $scope
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->var instanceof Variable || !is_string($node->var->name) || !$node->expr instanceof StaticPropertyFetch) {
            return null;
        }

        $resolved = StaticWriteTarget::resolve($node->expr, $scope, $this->reflectionProvider);
        if ($resolved === null) {
            return null;
        }

        return [StaticWriteTarget::functionKey($scope), $node->var->name, $resolved['class'] . '::$' . $resolved['property']];
    }
}
