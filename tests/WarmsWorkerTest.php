<?php

namespace Winter\Octane\Tests;

use Illuminate\Support\Facades\Log;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Classes\WarmsWorker;

/**
 * The reset's first full class scan happens when the worker starts, not on its first request.
 */
class WarmsWorkerTest extends PersistentWorkerTestCase
{
    /**
     * @return void
     */
    public function testTheClassScanRunsBeforeTheFirstRequest(): void
    {
        $count = new \ReflectionProperty(ResetsRequestState::class, 'examinedClassCount');
        $users = new \ReflectionProperty(ResetsRequestState::class, 'traitUsers');
        $savedUsers = $users->getValue();

        $count->setValue(null, 0);
        $users->setValue(null, []);

        try {
            $this->bootWorker();

            $this->assertGreaterThan(0, $count->getValue());
        }
        finally {
            $users->setValue(null, $savedUsers);
            $count->setValue(null, count(get_declared_classes()));
        }
    }

    /**
     * A step that fails is logged and the worker carries on.
     *
     * @return void
     */
    public function testAFailedStepIsLogged(): void
    {
        $this->app->bind('halcyon', fn () => throw new \RuntimeException('no resolver'));

        Log::spy();

        (new WarmsWorker())->handle((object) ['app' => $this->app]);

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * A broken log channel does not turn a failed step into a failed worker boot.
     *
     * @return void
     */
    public function testABrokenLogChannelDoesNotEscape(): void
    {
        $this->app->bind('halcyon', fn () => throw new \RuntimeException('no resolver'));

        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('log channel down'));

        (new WarmsWorker())->handle((object) ['app' => $this->app]);
    }
}
