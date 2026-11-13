<?php

namespace Winter\Octane\PHPStan;

use Winter\Octane\PHPStan\CallEdgeCollector;
use Winter\Octane\PHPStan\FinallyRestoreFunctionCollector;
use Winter\Octane\PHPStan\FinallyRestoreMethodCollector;
use Winter\Octane\PHPStan\ReflectionResetCollector;
use Winter\Octane\PHPStan\StaticPropertyWriteCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports static properties that request code writes but no worker reset clears.
 *
 * Under Octane one worker serves many requests, and a static property keeps its value between them. A write is
 * safe when:
 * - a plugin's resetWorkerState(), or a method it calls directly, writes the same property (a setter called with
 *   `null` counts), or code further down that call graph clears it: assigns a constant such as `[]` or `null`,
 *   unsets it, or sets it by name through reflection. Only a write to the whole property counts, not one array
 *   offset. Deeper reset code that fills a property does not count, since the same code may also run during a
 *   request;
 * - a closure that runs at a request boundary (an Octane operation event listener or a terminating() callback)
 *   clears it in its body or in a method it calls directly. A boundary closure is not followed further, because
 *   it often does conditional work;
 * - Winter.Octane resets it itself (its STATIC_CACHES and TRAIT_STATIC_CACHES lists);
 * - it happens in test code, or while the worker starts: in a plugin's register() or boot(), or in a function
 *   that only those call;
 * - the value comes only from code: constants, literals, reflection and pure built-in functions;
 * - a finally block in the same function clears the property (or the same array offset), or restores a value the
 *   function saved before and changes nowhere else, and the write is in the try block or in the plain assignments
 *   just before it;
 * - the property is a WeakMap, or is marked worker scoped (a WorkerScoped attribute, or the worker-scoped tag in
 *   its docblock or its class's), which says keeping the value for the worker's life is safe.
 * * A property declared in a trait is reported once for the trait, not once for each class that uses it.
 *
 * @implements Rule<CollectedDataNode>
 */
final class StaticStateRule implements Rule
{
    public const WORKER_SCOPED_ATTRIBUTE = 'WorkerScoped';

    public const WORKER_SCOPED_TAG = '@octane-worker-scoped';

    private const WINTER_RESET_CLASS = 'Winter\\Octane\\Classes\\ResetsRequestState';

    /**
     * @param ReflectionProvider $reflectionProvider
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * The recorded calls as caller => callees and as callee => incoming calls, with the plugins' resetWorkerState()
     * methods that make calls (a write directly in one is recognized from the write itself).
     *
     * @param array<string, list<list<array{0: string, 1: string, 2: string}>>> $collected
     *
     * @return array{
     *     0: array<string, array<string, true>>,
     *     1: array<string, list<array{0: string, 1: string}>>,
     *     2: list<string>
     * }
     */
    private function callGraph(array $collected): array
    {
        $edges      = [];
        $incoming   = [];
        $resetRoots = [];
        foreach ($this->flatten($collected) as [$caller, $callee, $context]) {
            $edges[$caller][$callee] = true;
            $incoming[$callee][]     = [$caller, $context];
            if ($context === CallEdgeCollector::CONTEXT_RESET) {
                $resetRoots[] = $caller;
            }
        }

        return [$edges, $incoming, array_values(array_unique($resetRoots))];
    }

    /**
     * The error for one property, at its first write site. A write that stores a constant (`= []`, `= null`) usually
     * clears the property, so a write that fills it is reported first when there is one.
     *
     * @param string                                  $reportKey
     * @param list<string>                            $classKeys
     * @param list<array{clears: bool, file: string, line: int}> $sites
     *
     * @return IdentifierRuleError
     */
    private function error(string $reportKey, array $classKeys, array $sites): IdentifierRuleError
    {
        usort(
            $sites,
            fn (array $a, array $b): int => [$a['clears'], $a['file'], $a['line']]
                <=> [$b['clears'], $b['file'], $b['line']]
        );
        $sites  = array_values(array_unique($sites, SORT_REGULAR));
        $others = count($sites) - 1;

        $detail = [];
        if (!in_array($reportKey, $classKeys, true)) {
            $detail[] = sprintf('in %d class%s', count($classKeys), count($classKeys) === 1 ? '' : 'es');
        }

        if ($others > 0) {
            $detail[] = sprintf('%d more write%s', $others, $others === 1 ? '' : 's');
        }

        return RuleErrorBuilder::message(sprintf(
            'Static property %s is written during a request, but no resetWorkerState() clears it, so the next'
                . ' request on the same Octane worker sees the old value%s.',
            $reportKey,
            $detail === [] ? '' : ' (' . implode(', ', $detail) . ')'
        ))
            ->identifier('winterOctane.staticPropertyNotReset')
            ->tip(sprintf(
                'Clear it in a plugin\'s resetWorkerState(). If keeping the value for the worker\'s life is safe, add'
                    . ' #[\\Winter\\Octane\\Attributes\\%s(\'<reason>\')] to the property.',
                self::WORKER_SCOPED_ATTRIBUTE
            ))
            ->file($sites[0]['file'])
            ->line($sites[0]['line'])
            ->build();
    }

    /**
     * The items of collected data that a collector returns as a list per node, as one list.
     *
     * @param array<string, list<list<mixed>>> $collected
     *
     * @return list<mixed>
     */
    private function flatten(array $collected): array
    {
        $items = [];
        foreach ($collected as $perNode) {
            foreach ($perNode as $list) {
                array_push($items, ...$list);
            }
        }

        return $items;
    }

    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * The functions that only run while the worker starts: every recorded call to them comes from a plugin's
     * register() or boot() body, from test code, or (outside a closure) from another such function, and at least one
     * comes from worker start. Test calls do not count either way, because the framework calls many methods
     * (component lifecycle methods, controllers, routes) that only tests call directly. A function with no recorded
     * callers, or one that only calls itself in a loop, is left out, so the answer stays on the side of reporting.
     *
     * @param array<string, list<array{0: string, 1: string}>> $incoming
     *
     * @return array<string, true>
     */
    private function onlyCalledAtWorkerStart(array $incoming): array
    {
        $only = [];
        do {
            $changed = false;
            foreach ($incoming as $callee => $calls) {
                if (isset($only[$callee]) || str_starts_with($callee, CallEdgeCollector::WRITE_PREFIX)) {
                    continue;
                }

                $fromStart = false;
                foreach ($calls as [$caller, $callContext]) {
                    if ($callContext === CallEdgeCollector::CONTEXT_TEST) {
                        continue;
                    } elseif (
                        $callContext === CallEdgeCollector::CONTEXT_START
                        || ($callContext === CallEdgeCollector::CONTEXT_NORMAL && isset($only[$caller]))
                    ) {
                        $fromStart = true;
                    } else {
                        continue 2;
                    }
                }

                if ($fromStart) {
                    $only[$callee] = true;
                    $changed       = true;
                }
            }
        } while ($changed);

        return $only;
    }

    /**
     * @param CollectedDataNode $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isOnlyFilesAnalysis()) {
            return [];
        }

        [$edges, $incoming, $resetRoots] = $this->callGraph($node->get(CallEdgeCollector::class));

        $resetReachable = $this->reachable($edges, $resetRoots);
        $resetDirect    = array_fill_keys($resetRoots, true);
        foreach ($resetRoots as $root) {
            $resetDirect += $edges[$root] ?? [];
        }

        $startOnly = $this->onlyCalledAtWorkerStart($incoming);
        $covered   = $this->winterResetProperties();
        foreach (array_keys($edges[CallEdgeCollector::REQUEST_BOUNDARY] ?? []) as $callee) {
            if (str_starts_with($callee, CallEdgeCollector::WRITE_PREFIX)) {
                $covered[substr($callee, strlen(CallEdgeCollector::WRITE_PREFIX))] = true;
            } else {
                $resetReachable[$callee] = true;
            }
        }

        $coveredNames = $this->reflectionResetNames($node->get(ReflectionResetCollector::class), $resetReachable);
        $restored     = [];
        $restores = array_merge(
            $this->flatten($node->get(FinallyRestoreMethodCollector::class)),
            $this->flatten($node->get(FinallyRestoreFunctionCollector::class))
        );
        foreach ($restores as [$function, $property, $start, $tryEnd, $finallyStart, $finallyEnd]) {
            $restored[$function][$property][] = [$start, $tryEnd, $finallyStart, $finallyEnd];
        }

        $pending = [];

        foreach ($this->flatten($node->get(StaticPropertyWriteCollector::class)) as $write) {
            $key = $write['class'] . '::$' . $write['property'];
            if (
                $write['offset'] === ''
                && (
                    $write['resetRoot']
                    || isset($resetDirect[$write['function']])
                    || (isset($resetReachable[$write['function']]) && $write['storesConstant'])
                )
            ) {
                $covered[$key] = true;
                continue;
            }

            if (
                $write['test']
                || $write['workerScoped']
                || $write['bootContext']
                || (!$write['inClosure'] && isset($startOnly[$write['function']]))
                || $write['derivedFromCode']
                || isset($coveredNames[$write['property']])
                || $this->isRestored($write, $key, $restored)
            ) {
                continue;
            }

            $reportKey = $write['trait'] === null ? $key : $write['trait'] . '::$' . $write['property'];

            $pending[$reportKey]['classes'][$key] = true;
            $pending[$reportKey]['sites'][]       = [
                'clears' => $write['storesConstant'],
                'file'   => $write['file'],
                'line'   => $write['line'],
            ];
        }

        $errors = [];
        foreach ($pending as $reportKey => ['classes' => $classes, 'sites' => $sites]) {
            $uncovered = array_diff_key($classes, $covered);
            if ($uncovered === [] || isset($covered[$reportKey])) {
                continue;
            }

            $errors[] = $this->error($reportKey, array_keys($uncovered), $sites);
        }

        return $errors;
    }

    /**
     * Whether a finally block in the same function puts the written property (or the written offset) back: the write
     * is in the lines the block protects (the try block and the straight-line statements before it) or is the finally
     * block's own write.
     *
     * @param array{function: string, offset: string, line: int} $write
     * @param string $key
     * @param array<string, array<string, list<array{0: int, 1: int, 2: int, 3: int}>>> $restored
     *
     * @return bool
     */
    private function isRestored(array $write, string $key, array $restored): bool
    {
        $targets = [$key];
        if ($write['offset'] !== '') {
            $targets[] = $key . $write['offset'];
        }

        foreach ($targets as $target) {
            foreach ($restored[$write['function']][$target] ?? [] as [$start, $tryEnd, $finallyStart, $finallyEnd]) {
                $line = $write['line'];
                if (($line >= $start && $line <= $tryEnd) || ($line >= $finallyStart && $line <= $finallyEnd)) {
                    return true;
                }
            }
        }

        return false;
    }
    /**
     * Every function reachable from the roots through the recorded calls.
     *
     * @param array<string, array<string, true>> $edges
     * @param list<string>                       $roots
     *
     * @return array<string, true>
     */
    private function reachable(array $edges, array $roots): array
    {
        $reachable = [];
        $queue     = $roots;
        while ($queue !== []) {
            $function = array_pop($queue);
            if (isset($reachable[$function])) {
                continue;
            }

            $reachable[$function] = true;
            foreach (array_keys($edges[$function] ?? []) as $callee) {
                $queue[] = $callee;
            }
        }

        return $reachable;
    }

    /**
     * The property names that reset code reaches through reflection.
     *
     * @param array<string, list<array{0: string, 1: string}>> $collected
     * @param array<string, true>                             $resetReachable
     *
     * @return array<string, true>
     */
    private function reflectionResetNames(array $collected, array $resetReachable): array
    {
        $names = [];
        foreach ($collected as $perNode) {
            foreach ($perNode as [$function, $property]) {
                if (isset($resetReachable[$function])) {
                    $names[$property] = true;
                }
            }
        }

        return $names;
    }

    /**
     * The static properties Winter.Octane resets itself, keyed by class (or trait) and property name.
     *
     * @return array<string, true>
     */
    private function winterResetProperties(): array
    {
        $covered = [];
        if (!$this->reflectionProvider->hasClass(self::WINTER_RESET_CLASS)) {
            return $covered;
        }

        $reflection = $this->reflectionProvider->getClass(self::WINTER_RESET_CLASS);
        foreach (['STATIC_CACHES', 'TRAIT_STATIC_CACHES'] as $constant) {
            if (!$reflection->hasConstant($constant)) {
                continue;
            }

            /*
             * The constant's value type is its declared @var type, which drops the class and property names, so the
             * names are read from the literal array in its definition: [Class::class => ['property' => ...], ...].
             */
            $classes = $reflection->getConstant($constant)->getValueExpr();
            if (!$classes instanceof Array_) {
                continue;
            }

            foreach ($classes->items as $classItem) {
                if (
                    !$classItem->key instanceof ClassConstFetch
                    || !$classItem->key->class instanceof Name
                    || !$classItem->value instanceof Array_
                ) {
                    continue;
                }

                foreach ($classItem->value->items as $propertyItem) {
                    if ($propertyItem->key instanceof String_) {
                        $covered[$classItem->key->class->toString() . '::$' . $propertyItem->key->value] = true;
                    }
                }
            }
        }

        return $covered;
    }
}
