<?php

/*
 * Diagnostic routes for the end-to-end tests (tests/E2e).
 *
 * Only registered when WINTER_OCTANE_SMOKE is set in the environment, which the tests set for the
 * server process they start. Never enable this on a real site: these routes exist to plant
 * request-scoped state and report whether it leaked into a later request.
 */
if (!env('WINTER_OCTANE_SMOKE')) {
    return;
}

use Illuminate\Support\Facades\Route;

/*
 * Class names are written out in full inside these closures because Swoole runs some of them as
 * tasks, which serializes them, and the serialized copy is read without this file's imports.
 */
$plantState = function (): void {
    \System\Twig\Loader::$allowInclude = true;
    \Illuminate\Support\Facades\View::share('octane_smoke_leak', 'planted');

    (new \ReflectionClass(\Cms\Classes\CodeParser::class))
        ->getProperty('cache')->setValue(null, ['octane-smoke' => 'dirty']);
};

$readState = function (): array {
    $parserCache = (new \ReflectionClass(\Cms\Classes\CodeParser::class))
        ->getProperty('cache')->getValue();

    return [
        'twig_gate_open'    => \System\Twig\Loader::$allowInclude,
        'view_leak_present' => array_key_exists('octane_smoke_leak', app('view')->getShared()),
        'code_parser_dirty' => array_key_exists('octane-smoke', $parserCache ?? []),
        'pid'               => getmypid(),
    ];
};

Route::get('/_octane-smoke/plant', function () use ($plantState) {
    $plantState();

    return response()->json(['planted' => true, 'pid' => getmypid()]);
});

Route::get('/_octane-smoke/read', function () use ($readState) {
    return response()->json($readState());
});

/*
 * Who the session belongs to, through the web middleware, so the tests can tell one browser's
 * session from another's.
 */
Route::middleware('web')->get('/_octane-smoke/whoami', function () {
    $user = \Backend\Facades\BackendAuth::getUser();

    return response()->json([
        'signed_in' => \Backend\Facades\BackendAuth::check(),
        'login'     => $user?->login,
        'session'   => session()->getId(),
        'token'     => csrf_token(),
        'pid'       => getmypid(),
    ]);
});

/*
 * Swoole only: tasks and ticks run in a task worker, which is a separate process with its own
 * application, and Octane resets it through TaskReceived and TickReceived instead of RequestReceived.
 */
if (app()->bound('Swoole\Http\Server')) {
    /*
     * The first task plants state and the second reads it. With one task worker both run in the
     * same process, one after the other.
     */
    Route::get('/_octane-smoke/task', function () use ($plantState, $readState) {
        [$planted] = \Laravel\Octane\Facades\Octane::concurrently([function () use ($plantState) {
            $plantState();

            return getmypid();
        }]);

        [$read] = \Laravel\Octane\Facades\Octane::concurrently([fn () => $readState()]);

        return response()->json(['planted_pid' => $planted, 'read' => $read, 'pid' => getmypid()]);
    });

    /*
     * Each tick records what it found when it started, then plants state for the next one. The
     * records go to the Octane cache, which the HTTP workers share with the task worker. Registered
     * once the application has booted, because Octane registers its cache driver when it boots,
     * after the plugins' routes are loaded.
     */
    app()->booted(function () use ($plantState, $readState) {
        \Laravel\Octane\Facades\Octane::tick('octane-smoke', function () use ($plantState, $readState) {
            $cache = \Illuminate\Support\Facades\Cache::store('octane');

            $cache->forever('octane-smoke-ticks', array_slice(
                array_merge($cache->get('octane-smoke-ticks', []), [$readState()]),
                -10
            ));

            $plantState();
        });
    });

    Route::get('/_octane-smoke/ticks', function () {
        return response()->json(\Illuminate\Support\Facades\Cache::store('octane')->get('octane-smoke-ticks', []));
    });
}
