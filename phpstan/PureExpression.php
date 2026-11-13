<?php

namespace Winter\Octane\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;

/**
 * Decides whether an expression's value comes only from the code itself: literals, constants, class names,
 * reflection, and built-in functions that return the same result for the same input. Such a value is the same in
 * every request, so keeping it in a static for the worker's life is safe.
 *
 * The check is deliberately narrow. Anything it does not recognize (a variable, a user function, a property read,
 * a database or cache call) makes the expression impure. One trusted shortcut: a callable array such as
 * `[self::class, 'normalize']` passed to array_map() is accepted without checking the method it names.
 */
final class PureExpression
{
    private const PURE_FUNCTIONS = [
        'array_column', 'array_combine', 'array_diff', 'array_fill_keys', 'array_filter', 'array_flip',
        'array_intersect', 'array_key_exists', 'array_keys', 'array_map', 'array_merge', 'array_reverse',
        'array_slice', 'array_unique', 'array_values', 'class_implements', 'class_parents', 'class_uses',
        'class_uses_recursive', 'count', 'explode', 'get_class_methods', 'implode', 'in_array', 'is_subclass_of',
        'lcfirst', 'ltrim', 'mb_strtolower', 'mb_strtoupper', 'method_exists', 'preg_quote', 'property_exists',
        'range', 'rtrim', 'sprintf', 'str_contains', 'str_ends_with', 'str_replace', 'str_starts_with', 'strlen',
        'strtolower', 'strtoupper', 'substr', 'trim', 'ucfirst',
    ];

    /**
     * Whether every node in the list is a pure expression (string parts of an interpolated string count).
     *
     * @param array<Node>  $nodes
     * @param Scope        $scope
     * @param list<string> $pureVariables
     *
     * @return bool
     */
    private static function all(array $nodes, Scope $scope, array $pureVariables): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof InterpolatedStringPart) {
                continue;
            }

            if (!$node instanceof Expr || !self::check($node, $scope, $pureVariables)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every argument of the call is a pure expression.
     *
     * @param FuncCall|MethodCall|New_ $call
     * @param Scope                    $scope
     * @param list<string>             $pureVariables
     *
     * @return bool
     */
    private static function args(FuncCall|MethodCall|New_ $call, Scope $scope, array $pureVariables): bool
    {
        if ($call->isFirstClassCallable()) {
            return false;
        }

        foreach ($call->getArgs() as $arg) {
            if (!self::check($arg->value, $scope, $pureVariables)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the expression's value comes only from code.
     *
     * @param Expr         $expr
     * @param Scope        $scope
     * @param list<string> $pureVariables Variables known to hold such a value, such as a closure's parameters.
     *
     * @return bool
     */
    public static function check(Expr $expr, Scope $scope, array $pureVariables = []): bool
    {
        if ($expr instanceof InterpolatedString) {
            return self::all($expr->parts, $scope, $pureVariables);
        } elseif ($expr instanceof Scalar || $expr instanceof ConstFetch) {
            return true;
        } elseif ($expr instanceof ClassConstFetch) {
            return $expr->class instanceof Name;
        } elseif ($expr instanceof Variable) {
            return is_string($expr->name) && in_array($expr->name, $pureVariables, true);
        } elseif ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if ($item->key !== null && !self::check($item->key, $scope, $pureVariables)) {
                    return false;
                }

                if (!self::check($item->value, $scope, $pureVariables)) {
                    return false;
                }
            }

            return true;
        } elseif ($expr instanceof New_) {
            return $expr->class instanceof Name
                && str_starts_with($scope->resolveName($expr->class), 'Reflection')
                && self::args($expr, $scope, $pureVariables);
        } elseif ($expr instanceof MethodCall) {
            return self::check($expr->var, $scope, $pureVariables) && self::args($expr, $scope, $pureVariables);
        } elseif ($expr instanceof FuncCall) {
            return $expr->name instanceof Name
                && in_array(strtolower(ltrim($expr->name->toString(), '\\')), self::PURE_FUNCTIONS, true)
                && self::args($expr, $scope, $pureVariables);
        } elseif ($expr instanceof ArrowFunction) {
            return self::check($expr->expr, $scope, array_merge($pureVariables, self::params($expr)));
        } elseif ($expr instanceof Closure) {
            return $expr->uses === [] && self::closureBody($expr, $scope, $pureVariables);
        } elseif ($expr instanceof BinaryOp) {
            return self::check($expr->left, $scope, $pureVariables)
                && self::check($expr->right, $scope, $pureVariables);
        } elseif ($expr instanceof BooleanNot || $expr instanceof UnaryMinus) {
            return self::check($expr->expr, $scope, $pureVariables);
        } elseif ($expr instanceof Ternary) {
            return self::check($expr->cond, $scope, $pureVariables)
                && ($expr->if === null || self::check($expr->if, $scope, $pureVariables))
                && self::check($expr->else, $scope, $pureVariables);
        } else {
            return false;
        }
    }

    /**
     * Whether a closure only returns pure expressions of its parameters.
     *
     * @param Closure      $closure
     * @param Scope        $scope
     * @param list<string> $pureVariables
     *
     * @return bool
     */
    private static function closureBody(Closure $closure, Scope $scope, array $pureVariables): bool
    {
        $pureVariables = array_merge($pureVariables, self::params($closure));
        foreach ($closure->stmts as $stmt) {
            if (
                !$stmt instanceof Return_
                || $stmt->expr === null
                || !self::check($stmt->expr, $scope, $pureVariables)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the expression computes a value from code: it is pure, and it is not just a constant such as `true`,
     * `null` or `[]`. Storing a constant in a static marks that something happened (a flag), which is state, not
     * a cached computation.
     *
     * @param Expr  $expr
     * @param Scope $scope
     *
     * @return bool
     */
    public static function computes(Expr $expr, Scope $scope): bool
    {
        return self::check($expr, $scope) && !self::isConstant($expr);
    }

    /**
     * Whether the expression is a constant: a literal, a constant, a class constant, or an array of those.
     *
     * @param Expr $expr
     *
     * @return bool
     */
    public static function isConstant(Expr $expr): bool
    {
        if ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if (($item->key !== null && !self::isConstant($item->key)) || !self::isConstant($item->value)) {
                    return false;
                }
            }

            return true;
        }

        return ($expr instanceof Scalar && !$expr instanceof InterpolatedString)
            || $expr instanceof ConstFetch
            || $expr instanceof ClassConstFetch
            || ($expr instanceof UnaryMinus && self::isConstant($expr->expr));
    }

    /**
     * The names of a closure's parameters.
     *
     * @param Closure|ArrowFunction $function
     *
     * @return list<string>
     */
    private static function params(Closure|ArrowFunction $function): array
    {
        $names = [];
        foreach ($function->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }
}
