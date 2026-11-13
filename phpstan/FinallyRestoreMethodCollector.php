<?php

namespace Winter\Octane\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;

/**
 * Runs FinallyRestoreCollector on each method body.
 *
 * @extends FinallyRestoreCollector<InClassMethodNode>
 */
final class FinallyRestoreMethodCollector extends FinallyRestoreCollector
{
    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @param InClassMethodNode $node
     * @param Scope             $scope
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        return $this->collect(array_values($node->getOriginalNode()->getStmts() ?? []), $scope);
    }
}
