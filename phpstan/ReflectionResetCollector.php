<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\StaticWriteTarget;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Records static properties reached by name through reflection, such as `$reflection->getProperty('has_run')`
 * or `new ReflectionProperty($class, 'globalTheme')`. When a reset method does this, the rule treats
 * every static property with that name as cleared, since it cannot tell which classes the loop visits.
 *
 * @implements Collector<Expr, array{0: string, 1: string}>
 */
final class ReflectionResetCollector implements Collector
{
    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @param Node  $node
     * @param Scope $scope
     *
     * @return array{0: string, 1: string}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $nameArg = null;
        if (
            $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'getproperty'
            && isset($node->getArgs()[0])
        ) {
            $nameArg = $node->getArgs()[0]->value;
        } elseif (
            $node instanceof New_
            && $node->class instanceof Name
            && $scope->resolveName($node->class) === 'ReflectionProperty'
            && isset($node->getArgs()[1])
        ) {
            $nameArg = $node->getArgs()[1]->value;
        } else {
            return null;
        }

        $names = $scope->getType($nameArg)->getConstantStrings();
        if (count($names) !== 1) {
            return null;
        }

        return [StaticWriteTarget::functionKey($scope), $names[0]->getValue()];
    }
}
