<?php namespace Winter\Octane;

use System\Classes\PluginBase;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Classes\ResolveAuthIpAddress;

/**
 * Laravel Octane support for Winter CMS.
 *
 * Registers Octane's own service provider and attaches Winter's request-state reset to the start
 * of every Octane operation. Core Winter only needs to register its back-end extensions in a worker
 * and dispatch interface listeners correctly; everything else a worker needs is cleared from here.
 */
class Plugin extends PluginBase
{
    /**
     * The request-boundary reset is a correctness invariant, not a feature: a worker serving
     * requests without it silently leaks state — auth, execution context, error-handler masks,
     * open transactions — across requests. Elevation keeps the plugin registered on the
     * privileged routes and commands where ordinary plugin initialization is skipped
     * (PluginManager::$noInit), so the first request a worker serves cannot decide that the
     * reset never attaches for the worker's whole lifetime.
     *
     * @var bool
     */
    public $elevated = true;

    /**
     * Warn when the worker booted without Winter's back-end registrations.
     *
     * Winter registers back-end navigation, widgets and settings only for back-end requests unless
     * it admits an Octane worker (wintercms/winter#1511). Without that change a worker serves every
     * back-end page with empty menus and settings, so the missing registration is logged.
     *
     * @return void
     */
    public function boot()
    {
        if (!$this->app->bound('Laravel\Octane\Contracts\Client')
            || !class_exists(\Backend\Classes\NavigationManager::class)
        ) {
            return;
        }

        $navigation = \Backend\Classes\NavigationManager::instance();
        $callbacks = (new \ReflectionProperty($navigation, 'callbacks'))->getValue($navigation);

        /*
         * Look for the callback core registers, not just any callback: a plugin registering its own
         * navigation would otherwise hide the missing core registration.
         */
        $coreRegistered = false;

        foreach ($callbacks as $callback) {
            if ($callback instanceof \Closure
                && (new \ReflectionFunction($callback))->getClosureScopeClass()?->getName() === \Backend\ServiceProvider::class
            ) {
                $coreRegistered = true;
                break;
            }
        }

        if (!$coreRegistered) {
            \Illuminate\Support\Facades\Log::warning(
                'Winter.Octane: no back-end navigation was registered in this worker, so back-end '
                . 'pages will have empty menus and settings. Update Winter to a version that '
                . 'includes wintercms/winter#1511.'
            );
        }
    }

    /**
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Octane',
            'description' => 'Serves Winter from a persistent application server (FrankenPHP, Swoole or RoadRunner) via Laravel Octane.',
            'author'      => 'Winter CMS',
            'icon'        => 'icon-bolt',
            'homepage'    => 'https://github.com/austinderrick/Winter.Octane',
        ];
    }

    /**
     * Register Octane's service provider and Winter's request-boundary reset.
     *
     * Winter disables Laravel's package auto-discovery (`app.loadDiscoveredPackages`), so the
     * installed `laravel/octane` package never registers its own provider. Without it the
     * `octane` binding is missing and Octane's ApplicationGateway fails on every request, so
     * this registration is a hard prerequisite for worker mode rather than a convenience.
     *
     * Registering the provider is inert under PHP-FPM: it binds services and reads
     * configuration, but Octane's events are only dispatched by an Octane worker.
     *
     * @return void
     */
    public function register()
    {
        /*
         * The plugin's composer.json requires laravel/octane, but the plugin's files can be
         * present before composer has run, and registration must degrade to a no-op rather than
         * fatal in that window.
         */
        if (!class_exists(\Laravel\Octane\OctaneServiceProvider::class)) {
            return;
        }

        /*
         * Octane registers its post-operation cleanup against an interface. Storm's dispatcher only
         * handles interface listeners once it overrides addInterfaceListeners(); without that every
         * RequestTerminated dispatch throws, so registration is refused and the reason is logged.
         */
        $dispatcher = new \ReflectionMethod(\Winter\Storm\Events\Dispatcher::class, 'addInterfaceListeners');

        if ($dispatcher->getDeclaringClass()->getName() !== \Winter\Storm\Events\Dispatcher::class) {
            $message = 'Winter.Octane: this version of Storm cannot dispatch interface listeners, so '
                . 'Octane was not registered. Update winter/storm before serving through Octane.';

            /*
             * Inside a worker, fail the boot so the server says why. Under PHP-FPM the plugin has
             * nothing to do, so stay quiet rather than log on every request. Console commands
             * (octane:start among them) log once per run.
             */
            if ($this->app->bound('Laravel\Octane\Contracts\Client')) {
                throw new \RuntimeException($message);
            }

            if ($this->app->runningInConsole()) {
                \Illuminate\Support\Facades\Log::warning($message);
            }

            return;
        }

        $this->app->register(\Laravel\Octane\OctaneServiceProvider::class);

        /*
         * The reset deliberately runs at the start of every operation rather than the end. An
         * exception that escapes the HTTP kernel skips ApplicationGateway::terminate(), so
         * RequestTerminated — and every listener registered against Octane's OperationTerminated
         * contract — never fires. Cleaning up on the way in is the only boundary that also holds
         * after a failed operation.
         *
         * WorkerErrorOccurred is included so a failed operation is cleaned up promptly rather than
         * leaving the worker dirty until the next request arrives.
         *
         * The listeners are attached at the highest priority Storm's dispatcher accepts, so the
         * reset runs FIRST — before Octane's own listeners (attached at default priority in
         * OctaneServiceProvider::boot()) and before anything a third party registers. Three
         * properties depend on this, and a test pins each:
         *
         *  - The reset cannot be skipped. The dispatcher stops propagation when a listener
         *    returns false, and Octane's ApplicationGateway proceeds into the kernel regardless,
         *    so a reset attached later could be silently bypassed for an operation.
         *  - Every other listener starts from a clean slate rather than observing the previous
         *    operation's state.
         *  - The view-share baseline is captured before Octane injects per-operation objects
         *    (the sandbox, via GiveNewApplicationInstanceToViewFactory) into shared data, and is
         *    restored before Octane re-injects them for the current operation.
         *
         * Running first is safe because the reset reads NOTHING from the incoming request: it
         * only discards previous state. The auth manager's client address is recorded later, by
         * ResolveAuthIpAddress, after the trusted-proxy middleware has run.
         */
        foreach ([
            \Laravel\Octane\Events\RequestReceived::class,
            \Laravel\Octane\Events\TaskReceived::class,
            \Laravel\Octane\Events\TickReceived::class,
            \Laravel\Octane\Events\WorkerErrorOccurred::class,
        ] as $event) {
            $this->app->make('events')->listen($event, [ResetsRequestState::class, 'handle'], PHP_INT_MAX);
        }

        /*
         * Appended to the global stack, so it runs after the trusted-proxy middleware.
         */
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            $kernel->pushMiddleware(ResolveAuthIpAddress::class);
        }
    }
}
