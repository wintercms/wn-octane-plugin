<?php

namespace Winter\Octane\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Records the static properties that a `finally` block puts back, as a whole or at one array offset, with the lines it
 * protects, keyed by the function it sits in. A function that sets a static and puts it back in `finally` leaves
 * nothing behind for the next request, so the rule skips those writes.
 *
 * The finally block has to clear the target (assign a constant or unset it) or assign it a local variable that the
 * function saved the property into (`$previous = self::$user;`) and changes nowhere else, not even through an array
 * offset. Only writes inside the try block are protected: a write before it can be followed by something that throws
 * or returns, and then the finally block never runs.
 *
 * It needs the statements around each try block, so it runs once per method (FinallyRestoreMethodCollector) and once
 * per function (FinallyRestoreFunctionCollector). A try block inside a closure is not considered.
 *
 * @template TNode of Node
 * @implements Collector<TNode, list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>>
 */
abstract class FinallyRestoreCollector implements Collector
{
    /**
     * @param ReflectionProvider $reflectionProvider
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Whether a value assigned in a finally block clears the property (a constant) or restores it (a local variable
     * the function saved this property into and changes nowhere else).
     *
     * @param Expr                  $value
     * @param string                $key
     * @param array<string, string> $saves
     * @param array<string, int>    $assignments
     *
     * @return bool
     */
    private static function clearsOrRestores(Expr $value, string $key, array $saves, array $assignments): bool
    {
        if (PureExpression::isConstant($value)) {
            return true;
        }

        return $value instanceof Variable
            && is_string($value->name)
            && ($saves[$value->name] ?? null) === $key
            && ($assignments[$value->name] ?? 0) === 1;
    }

    /**
     * The nodes under the given ones, without descending into closures, arrow functions or classes.
     *
     * @param array<Node> $nodes
     *
     * @return list<Node>
     */
    private static function descendants(array $nodes): array
    {
        $found = [];
        foreach ($nodes as $node) {
            $found[] = $node;
            if ($node instanceof FunctionLike || $node instanceof Class_) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child    = $node->$name;
                $children = array_values(array_filter(
                    is_array($child) ? $child : [$child],
                    fn ($item): bool => $item instanceof Node
                ));
                array_push($found, ...self::descendants($children));
            }
        }

        return $found;
    }

    /**
     * The local variables a node changes: assignment targets (including list destructuring and writes to an offset or
     * property of the variable), `++` and `--`, unset(), foreach targets and variables a closure takes by reference.
     *
     * @param Node $node
     *
     * @return list<string>
     */
    private static function changedVariables(Node $node): array
    {
        $targets = [];
        if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
            $targets[] = $node->var;
        } elseif ($node instanceof PreInc || $node instanceof PostInc || $node instanceof PreDec || $node instanceof PostDec) {
            $targets[] = $node->var;
        } elseif ($node instanceof Unset_) {
            $targets = $node->vars;
        } elseif ($node instanceof Foreach_) {
            $targets = array_filter([$node->keyVar, $node->valueVar]);
        } elseif ($node instanceof Closure) {
            foreach ($node->uses as $use) {
                if ($use->byRef) {
                    $targets[] = $use->var;
                }
            }
        } else {
            return [];
        }

        $names = [];
        while ($targets !== []) {
            $target = array_pop($targets);
            if ($target instanceof Variable && is_string($target->name)) {
                $names[] = $target->name;
            } elseif ($target instanceof List_ || $target instanceof Array_) {
                foreach ($target->items as $item) {
                    if ($item !== null) {
                        $targets[] = $item->value;
                    }
                }
            } elseif ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
                // A write to an offset or a property of a local array or object changes that local variable.
                $targets[] = $target->var;
            } else {
                // Static properties are not local variables.
            }
        }

        return $names;
    }

    /**
     * The restores in one method or function body.
     *
     * @param list<Stmt> $body
     * @param Scope      $scope
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>|null
     */
    protected function collect(array $body, Scope $scope): ?array
    {
        $all  = self::descendants($body);

        // Local variables that hold a saved static property, and how many times each local variable is changed.
        $assignments = [];
        $saves       = [];
        foreach ($all as $child) {
            foreach (self::changedVariables($child) as $name) {
                $assignments[$name] = ($assignments[$name] ?? 0) + 1;
            }

            if (!$child instanceof Assign || !$child->var instanceof Variable || !$child->expr instanceof StaticPropertyFetch) {
                continue;
            }

            $name = $child->var->name;
            if (is_string($name)) {
                $resolved = StaticWriteTarget::resolve($child->expr, $scope, $this->reflectionProvider);
                if ($resolved !== null) {
                    $saves[$name] = $resolved['class'] . '::$' . $resolved['property'];
                }
            }
        }

        $function = StaticWriteTarget::functionKey($scope);
        $restored = [];
        foreach ($all as $stmt) {
            if (!$stmt instanceof TryCatch || $stmt->finally === null || $stmt->stmts === []) {
                continue;
            }

            foreach ($this->restores($stmt, $scope, $saves, $assignments) as $property) {
                $restored[] = [
                    $function,
                    $property,
                    $stmt->stmts[0]->getStartLine(),
                    $stmt->stmts[count($stmt->stmts) - 1]->getEndLine(),
                    $stmt->finally->getStartLine(),
                    $stmt->finally->getEndLine(),
                ];
            }
        }

        return $restored === [] ? null : $restored;
    }

    /**
     * The targets a finally block clears or restores, as "Class::$property" plus any array offsets.
     *
     * @param TryCatch              $try
     * @param Scope                 $scope
     * @param array<string, string> $saves       Local variable => the static property saved into it.
     * @param array<string, int>    $assignments Local variable => how many times the function changes it.
     *
     * @return list<string>
     */
    private function restores(TryCatch $try, Scope $scope, array $saves, array $assignments): array
    {
        if ($try->finally === null) {
            return [];
        }

        $targets = [];
        foreach (self::descendants($try->finally->stmts) as $node) {
            if ($node instanceof Unset_) {
                foreach ($node->vars as $var) {
                    $targets[] = [$var, null];
                }
            } elseif ($node instanceof Assign) {
                $targets[] = [$node->var, $node->expr];
            } else {
                // Only assignments and unset() put a value back.
            }
        }

        $properties = [];
        foreach ($targets as [$target, $value]) {
            $path = StaticWriteTarget::offsetPath($target);
            if (str_contains($path, '[]')) {
                continue;
            }

            $resolved = StaticWriteTarget::resolve($target, $scope, $this->reflectionProvider);
            if ($resolved === null) {
                continue;
            }

            $key = $resolved['class'] . '::$' . $resolved['property'];
            if ($value === null || self::clearsOrRestores($value, $key, $saves, $assignments)) {
                $properties[] = $key . $path;
            }
        }

        return $properties;
    }
}
