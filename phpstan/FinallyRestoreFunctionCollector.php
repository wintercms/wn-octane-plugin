<?php

namespace Winter\Octane\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InFunctionNode;

/**
 * Runs FinallyRestoreCollector on each function body.
 *
 * @extends FinallyRestoreCollector<InFunctionNode>
 */
final class FinallyRestoreFunctionCollector extends FinallyRestoreCollector
{
    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return InFunctionNode::class;
    }

    /**
     * @param InFunctionNode $node
     * @param Scope          $scope
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        return $this->collect(array_values($node->getOriginalNode()->getStmts()), $scope);
    }
}
