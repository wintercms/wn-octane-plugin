<?php

namespace Winter\Octane\Tests;

use Illuminate\Http\Request;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\WorkerErrorOccurred;
use System\Classes\PluginBase;
use Winter\Octane\Classes\ResetsRequestState;

/**
 * The reset's behavior across the operation types and failure modes a worker actually sees.
 */
class ResetLifecycleTest extends PersistentWorkerTestCase
{
    /**
     * Builds a plugin that counts how many times its reset ran.
     *
     * @return \System\Classes\PluginBase
     */
    protected function makeCountingPlugin(): PluginBase
    {
        return new class ($this->app) extends PluginBase
        {
            /**
             * @var int Number of times the reset was invoked.
             */
            public int $resets = 0;

            /**
             * Counts each time the reset reaches this plugin.
             *
             * @return void
             */
            public function resetWorkerState(): void
            {
                $this->resets++;
            }
        };
    }

    /**
     * Octane fires TaskReceived for concurrent tasks and TickReceived on the interval timer.
     * Both serve application code from the same worker, so both need the reset.
     *
     * @return void
     */
    public function testTaskAndTickOperationsAreAlsoReset(): void
    {
        $app = $this->bootWorker();

        $probe = $this->makeCountingPlugin();

        $this->withFakePlugins(['Winter.Tests.TaskTickProbe' => $probe], function () use ($app) {
            $app['events']->dispatch(new TaskReceived($app, $app, []));
            $app['events']->dispatch(new TickReceived($app, $app));
        });

        $this->assertSame(
            2,
            $probe->resets,
            'TaskReceived and TickReceived must each trigger the reset; tasks and ticks run '
            . 'application code from the same worker that serves requests.'
        );
    }

    /**
     * One plugin failing on the worker-error path must not abandon the plugins after it.
     *
     * On the ordinary path a failing plugin fails the operation. While the worker is already
     * handling an error there is no operation left to fail, and skipping the remaining plugins
     * would leave exactly the cross-user state the reset exists to clear.
     *
     * @return void
     */
    public function testAFailingPluginDoesNotAbandonTheRemainingPluginsOnTheErrorPath(): void
    {
        $app = $this->bootWorker();

        $failing = new class ($app) extends PluginBase
        {
            /**
             * Fails every time the reset reaches this plugin.
             *
             * @return void
             */
            public function resetWorkerState(): void
            {
                throw new \RuntimeException('deliberate reset failure');
            }
        };

        $survivor = $this->makeCountingPlugin();

        \Illuminate\Support\Facades\Log::spy();

        /*
         * Keyed so the failing plugin is iterated first; the assertion is that the survivor is
         * still reached afterwards.
         */
        $this->withFakePlugins([
            'Winter.Tests.AFailingReset' => $failing,
            'Winter.Tests.Survivor'      => $survivor,
        ], function () use ($app) {
            (new ResetsRequestState())->handle(
                new WorkerErrorOccurred(new \RuntimeException('the original failure'), $app)
            );
        });

        $this->assertSame(
            1,
            $survivor->resets,
            'The plugin after a failing one must still be reset on the worker-error path.'
        );
    }

    /**
     * A broken log channel must not turn error-path logging into the crash it exists to prevent.
     *
     * WorkerErrorOccurred is dispatched from inside the worker loop's catch block. If the reset's
     * own logging throws there, the exception escapes the loop and kills the process, which is
     * the exact failure mode this path is meant to close.
     *
     * @return void
     */
    public function testABrokenLoggerCannotEscapeTheWorkerErrorPath(): void
    {
        $app = $this->bootWorker();

        $failing = new class ($app) extends PluginBase
        {
            /**
             * Fails every time the reset reaches this plugin.
             *
             * @return void
             */
            public function resetWorkerState(): void
            {
                throw new \RuntimeException('deliberate reset failure');
            }
        };

        \Illuminate\Support\Facades\Log::swap(new class
        {
            /**
             * Fails every logging call, as a broken log channel would.
             *
             * @param string $method
             * @param array<int, mixed> $arguments
             * @return mixed
             */
            public function __call(string $method, array $arguments): mixed
            {
                throw new \RuntimeException('the log channel is broken too');
            }
        });

        try {
            $this->withFakePlugins(['Winter.Tests.FailingReset' => $failing], function () use ($app) {
                (new ResetsRequestState())->handle(
                    new WorkerErrorOccurred(new \RuntimeException('the original failure'), $app)
                );
            });

            $this->assertTrue(true, 'Nothing escaped the worker-error path.');
        }
        finally {
            \Illuminate\Support\Facades\Log::clearResolvedInstances();
        }
    }

    /**
     * The reset can run twice for one operation without changing the outcome.
     *
     * WorkerErrorOccurred after a failed request means the reset may run once for the failed
     * operation and again when the next request arrives, so every step has to tolerate finding
     * the state already clean.
     *
     * @return void
     */
    public function testRunningTheResetTwiceIsHarmless(): void
    {
        $app = $this->bootWorker();

        $probe = $this->makeCountingPlugin();

        $this->addWorkerRoute('_worker/twice', fn () => 'ok');

        $this->withFakePlugins(['Winter.Tests.TwiceProbe' => $probe], function () use ($app) {
            $listener = new ResetsRequestState();
            $listener->handle(new WorkerErrorOccurred(new \RuntimeException('failed op'), $app));

            $this->dispatchWorkerRequests(Request::create('/_worker/twice', 'GET'));
        }, keepExisting: true);

        $this->assertSame(2, $probe->resets, 'Both resets should have reached the plugin.');
        $this->assertSame(
            200,
            $this->workerResults[0]->getStatusCode(),
            'A request following a back-to-back double reset must still be served normally.'
        );
    }

    /**
     * The elevated flag is what keeps the reset attached on privileged routes and commands where
     * ordinary plugin initialization is skipped. Losing it would not fail any functional test,
     * so it is pinned here.
     *
     * @return void
     */
    public function testThePluginIsElevated(): void
    {
        $plugin = new \Winter\Octane\Plugin($this->app);

        $this->assertTrue(
            $plugin->elevated,
            'Winter.Octane must be elevated: with PluginManager::$noInit set, a non-elevated '
            . 'plugin never registers, and a worker that booted on a privileged route would '
            . 'serve every request with no state reset at all.'
        );
    }
}
