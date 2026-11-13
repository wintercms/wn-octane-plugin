<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\PureExpression;
use Winter\Octane\PHPStan\StaticStateRule;
use Winter\Octane\PHPStan\StaticWriteTarget;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Static_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports `static $var` declarations inside functions. Nothing outside the function can clear them, so under
 * Octane their value carries from one request to the next for the life of the worker.
 *
 * A static variable is not reported when its initial value and every value the function assigns to it come only
 * from code (see PureExpression), since every request would compute the same value.
 *
 * @implements Rule<FunctionLike>
 */
final class StaticVariableRule implements Rule
{
    /**
     * The static declarations and the writes to variables directly in a function body, skipping nested closures.
     *
     * @param array<Node>           $nodes
     * @param list<Static_>         $statics
     * @param list<Assign|AssignOp|AssignRef|PreInc|PostInc|PreDec|PostDec> $assigns
     *
     * @return void
     */
    private static function collect(array $nodes, array &$statics, array &$assigns): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof FunctionLike) {
                continue;
            }

            if ($node instanceof Static_) {
                $statics[] = $node;
            } elseif (StaticWriteTarget::isWriteNode($node)) {
                $assigns[] = $node;
            } else {
                // Other nodes are only searched for nested statements.
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                if ($child instanceof Node) {
                    self::collect([$child], $statics, $assigns);
                } elseif (is_array($child)) {
                    self::collect(array_filter($child, fn ($item): bool => $item instanceof Node), $statics, $assigns);
                } else {
                    // A scalar subnode, such as a name or a flag, holds no statements.
                }
            }
        }
    }

    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * Whether the variable's initial value comes only from code, and every value assigned to it is computed from
     * code. A constant assigned later (`$done = true`) is a flag, and a write through an offset, `++` or `.=` adds to
     * the value, so neither counts.
     *
     * @param Expr|null             $default
     * @param string                $name
     * @param list<Assign|AssignOp|AssignRef|PreInc|PostInc|PreDec|PostDec> $assigns
     * @param Scope                 $scope
     *
     * @return bool
     */
    private function isDerivedFromCode(?Expr $default, string $name, array $assigns, Scope $scope): bool
    {
        if ($default !== null && !PureExpression::check($default, $scope)) {
            return false;
        }

        foreach ($assigns as $write) {
            $target = StaticWriteTarget::root($write->var);

            if (!$target instanceof Variable || $target->name !== $name) {
                continue;
            }

            $plain = $write->var instanceof Variable && ($write instanceof Assign || $write instanceof Coalesce);
            if (!$plain || !PureExpression::computes($write->expr, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param FunctionLike $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (StaticWriteTarget::isTestFile($scope->getFile()) || $node->getStmts() === null) {
            return [];
        }

        $statics = [];
        $assigns = [];
        self::collect($node->getStmts(), $statics, $assigns);

        $errors = [];
        foreach ($statics as $static) {
            if (str_contains((string) $static->getDocComment()?->getText(), StaticStateRule::WORKER_SCOPED_TAG)) {
                continue;
            }

            $names = [];
            foreach ($static->vars as $var) {
                $name = $var->var->name;
                if (!is_string($name) || !$this->isDerivedFromCode($var->default, $name, $assigns, $scope)) {
                    $names[] = '$' . (is_string($name) ? $name : '?');
                }
            }

            if ($names === []) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Static variable %s keeps its value across requests on the same Octane worker, and no reset can'
                    . ' clear it.',
                implode(', ', $names)
            ))
                ->identifier('winterOctane.staticVariable')
                ->tip(sprintf(
                    'Use a static property cleared in resetWorkerState() or a request-scoped cache. If keeping the'
                        . ' value for the worker\'s life is safe, put a "/** %s <reason> */" docblock above the'
                        . ' statement.',
                    StaticStateRule::WORKER_SCOPED_TAG
                ))
                ->line($static->getStartLine())
                ->build();
        }

        return $errors;
    }
}
