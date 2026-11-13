<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\PureExpression;
use Winter\Octane\PHPStan\StaticWriteTarget;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Stmt\Unset_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Records every runtime write to a static property, with the function it happens in.
 *
 * @implements Collector<Node, list<array{
 *     class: string,
 *     property: string,
 *     trait: string|null,
 *     workerScoped: bool,
 *     function: string,
 *     file: string,
 *     line: int,
 *     test: bool,
 *     inClosure: bool,
 *     derivedFromCode: bool,
 *     storesConstant: bool,
 *     bootContext: bool,
 *     resetRoot: bool,
 *     offset: string
 * }>>
 */
final class StaticPropertyWriteCollector implements Collector
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
        return Node::class;
    }

    /**
     * Whether the write stores a value that comes only from code, at array offsets that also come only from code
     * (`self::$x ??= new ReflectionProperty(...)`, `self::$codes[static::class] = ...`). Appending (`[]=`) does
     * not count, since it grows with every request.
     *
     * @param Node  $node
     * @param Node  $target
     * @param Scope $scope
     *
     * @return bool
     */
    private static function isDerivedFromCode(Node $node, Node $target, Scope $scope): bool
    {
        if (!($node instanceof Assign || $node instanceof Coalesce) || !PureExpression::computes($node->expr, $scope)) {
            return false;
        }

        while ($target instanceof ArrayDimFetch) {
            if ($target->dim === null || !PureExpression::check($target->dim, $scope)) {
                return false;
            }

            $target = $target->var;
        }

        return true;
    }

    /**
     * @param Node  $node
     * @param Scope $scope
     *
     * @return list<array<string, mixed>>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (StaticWriteTarget::isWriteNode($node)) {
            $targets = [$node->var];
        } elseif ($node instanceof Unset_) {
            $targets = $node->vars;
        } else {
            return null;
        }

        $writes = [];
        foreach ($targets as $target) {
            $resolved = StaticWriteTarget::resolve($target, $scope, $this->reflectionProvider);
            if ($resolved === null) {
                continue;
            }

            $writes[] = $resolved + [
                'function'        => StaticWriteTarget::functionKey($scope),
                'file'            => $scope->getTraitReflection()?->getFileName() ?? $scope->getFile(),
                'line'            => $node->getStartLine(),
                'test'            => StaticWriteTarget::isTestFile($scope->getFile()),
                'inClosure'       => $scope->isInAnonymousFunction(),
                'derivedFromCode' => self::isDerivedFromCode($node, $target, $scope),
                'storesConstant'  => $node instanceof Unset_
                    || ($node instanceof Assign && PureExpression::isConstant($node->expr)),
                'bootContext'     => StaticWriteTarget::isWorkerStart($scope),
                'resetRoot'       => StaticWriteTarget::isResetRoot($scope),
                'offset'          => StaticWriteTarget::offsetPath($target),
            ];
        }

        return $writes === [] ? null : $writes;
    }
}
