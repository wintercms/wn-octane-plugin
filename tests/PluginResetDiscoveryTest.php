<?php

namespace Winter\Octane\Tests;

use System\Classes\PluginBase;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Contracts\ResetsWorkerState;

/**
 * A plugin must be reset whether it names the contract or merely provides the method.
 *
 * The second form is the one that matters in practice. `implements` is resolved when the class is
 * loaded, so a plugin naming ResetsWorkerState cannot be loaded at all by a Winter whose Storm
 * predates the interface -- it fatals on every request, under PHP-FPM as much as under a worker,
 * because loading the plugin is what fails. A plugin distributed against several Winter versions
 * therefore cannot name it, and if the reset skipped those plugins they would keep exactly the
 * request-derived state this class exists to clear.
 */
class PluginResetDiscoveryTest extends PersistentWorkerTestCase
{
    /**
     * Both forms are discovered, and a plugin offering neither is left alone rather than fatalling.
     *
     * @return void
     */
    public function testAPluginIsResetByContractOrByMethod(): void
    {
        $app = $this->bootWorker();

        $byMethod = new PluginResetDiscoveryMethodPlugin($app);
        $neither  = new PluginResetDiscoveryInertPlugin($app);

        /*
         * Built here, and only where the contract exists, for the very reason this test covers. A
         * file-scope class naming ResetsWorkerState cannot be loaded by a Winter whose Storm predates
         * the interface, and PHPUnit loads every test file before running anything, so declaring one
         * would take the whole suite down rather than fail this test. An anonymous class is evaluated
         * when the line runs, which is after the check.
         */
        $byContract = null;
        if (interface_exists(ResetsWorkerState::class)) {
            $byContract = new class ($app) extends PluginBase implements ResetsWorkerState
            {
                /**
                 * @var int Number of times the reset was invoked.
                 */
                public $resets = 0;

                /**
                 * @return void
                 */
                public function resetWorkerState(): void
                {
                    $this->resets++;
                }
            };
        }

        $fakes = [
            'Winter.Tests.ResetByMethod' => $byMethod,
            'Winter.Tests.ResetNeither'  => $neither,
        ];

        if ($byContract !== null) {
            $fakes['Winter.Tests.ResetByContract'] = $byContract;
        }

        $this->withFakePlugins($fakes, function () {
            (new \ReflectionMethod(ResetsRequestState::class, 'resetPlugins'))
                ->invoke(new ResetsRequestState());
        });

        if ($byContract !== null) {
            $this->assertSame(
                1,
                $byContract->resets,
                'A plugin implementing ResetsWorkerState was not reset.'
            );
        }

        $this->assertSame(
            1,
            $byMethod->resets,
            'A plugin declaring resetWorkerState() without implementing the contract was not reset. '
            . 'Such a plugin cannot name the interface without becoming unloadable on an older Storm.'
        );
        $this->assertFalse(
            $neither->touched,
            'A plugin providing neither the contract nor the method should be skipped.'
        );
    }

    /**
     * On the WorkerErrorOccurred path a failed plugin reset is logged, not thrown.
     *
     * That event is dispatched from inside Octane's own error handling. A throw from the reset
     * there would run ahead of Octane's ReportException and StopWorkerIfNecessary listeners,
     * swallowing the report of the original failure and preventing the worker from being retired.
     * The ordinary path must keep throwing: the next RequestReceived is what surfaces a
     * persistently broken plugin.
     */
    public function testAFailingResetIsLoggedNotThrownWhileHandlingAWorkerError(): void
    {
        $app = $this->bootWorker();

        $failing = new class ($app) extends PluginBase
        {
            public function resetWorkerState(): void
            {
                throw new \RuntimeException('deliberate reset failure');
            }
        };

        \Illuminate\Support\Facades\Log::spy();

        $this->withFakePlugins(['Winter.Tests.FailingReset' => $failing], function () use ($app) {
            (new ResetsRequestState())->handle(new \Laravel\Octane\Events\WorkerErrorOccurred(
                new \RuntimeException('the original operation failure'),
                $app
            ));

            \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();

            /*
             * Same failing plugin, ordinary path: the throw must survive, or a broken plugin
             * degrades into a silent per-request log line.
             */
            $threw = false;

            try {
                (new \ReflectionMethod(ResetsRequestState::class, 'resetPlugins'))
                    ->invoke(new ResetsRequestState());
            }
            catch (\RuntimeException $ex) {
                $threw = true;
            }

            $this->assertTrue($threw, 'A failing plugin reset must still fail an ordinary operation.');
        });
    }
}

/**
 * Resettable by declaring the method alone, which is what a plugin spanning Storm versions must do.
 */
class PluginResetDiscoveryMethodPlugin extends PluginBase
{
    /**
     * @var int Number of times the reset was invoked.
     */
    public $resets = 0;

    /**
     * @return void
     */
    public function resetWorkerState(): void
    {
        $this->resets++;
    }
}

/**
 * Holds no request-derived state and offers neither form.
 */
class PluginResetDiscoveryInertPlugin extends PluginBase
{
    /**
     * @var bool Set only if something reached this plugin.
     */
    public $touched = false;
}
