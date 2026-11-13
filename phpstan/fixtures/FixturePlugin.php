<?php

namespace Winter\Octane\PHPStan\Fixtures;

use ReflectionClass;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Winter\Octane\PHPStan\Fixtures\RequestState;
use Winter\Octane\PHPStan\Fixtures\Resetter;
use Laravel\Octane\Events\RequestReceived;
use System\Classes\PluginBase;

/**
 * A plugin that writes state while it boots, registers request boundary listeners, and resets state between
 * requests.
 */
class FixturePlugin extends PluginBase
{
    /**
     * Runs once when the worker starts. The closures run later, per request.
     *
     * @return void
     */
    public function boot(): void
    {
        RequestState::$setAtBoot = ['booted'];
        RequestState::addAtBoot('booted');

        Event::listen('fixture.event', function (): void {
            RequestState::$setInBootClosure[] = 'event'; // expect: winterOctane.staticPropertyNotReset
        });
        Event::listen(RequestReceived::class, function (): void {
            RequestState::beginRequest();
        });
        App::terminating(function (): void {
            RequestState::$clearedAtTerminate = [];
            RequestState::flushOuter();
        });
    }

    /**
     * Clears request state, through a helper the rule has to follow.
     *
     * @return void
     */
    public function resetWorkerState(): void
    {
        Resetter::run();
    }
}

/**
 * Called from resetWorkerState(): one static by name, one through reflection.
 */
class Resetter
{
    /**
     * @return void
     */
    public static function run(): void
    {
        RequestState::$clearedByReset = [];
        RequestState::addFilled('reset');

        $reflection = new ReflectionClass(RequestState::class);
        $reflection->getProperty('clearedByReflection')->setValue(null, []);
    }
}

/**
 * A plugin whose resetWorkerState() only assigns, with no calls.
 */
class AssignOnlyPlugin extends PluginBase
{
    /**
     * @return void
     */
    public function resetWorkerState(): void
    {
        RequestState::$clearedByPluginWithoutCalls = [];
    }
}

/**
 * Not a plugin, so Winter.Octane never calls its resetWorkerState().
 */
class NotAPlugin
{
    /**
     * @return void
     */
    public function resetWorkerState(): void
    {
        RequestState::$clearedByNonPlugin = [];
        RequestState::beginRequest();
    }
}
