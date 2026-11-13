<?php

namespace Winter\Octane\PHPStan\Fixtures\Tests;

use Illuminate\Support\Facades\App;
use System\Classes\PluginBase;
use Winter\Octane\PHPStan\Fixtures\RequestState;

/**
 * A plugin in test code. Its resetWorkerState() and the listener it registers never run in a production worker, so
 * they must not count as resets for the production statics they clear.
 */
class TestResetPlugin extends PluginBase
{
    /**
     * @return void
     */
    public function boot(): void
    {
        App::terminating(fn () => RequestState::$resetByTestListener = []);
    }

    /**
     * @return void
     */
    public function resetWorkerState(): void
    {
        RequestState::$resetByTestPlugin = [];
    }
}
