<?php namespace Winter\Octane;

use System\Classes\PluginBase;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Classes\ResolveAuthIpAddress;
use Winter\Octane\Classes\ServePublicFiles;

/**
 * Laravel Octane support for Winter CMS.
 *
 * Registers Octane's own service provider, registers Winter's back-end extensions in a worker, and
 * attaches Winter's request-state reset to the start of every Octane operation. Core Winter only
 * needs to dispatch interface listeners correctly; everything else a worker needs is handled here.
 */
class Plugin extends PluginBase
{
    /**
     * The registration methods each core provider runs only for back-end requests, in the order
     * core runs them: System registers the Backend and Cms providers before its own registrations.
     *
     * @var array<class-string, list<string>>
     */
    protected const BACKEND_REGISTRATIONS = [
        \Backend\ServiceProvider::class => [
            'registerBackendNavigation',
            'registerBackendReportWidgets',
            'registerBackendWidgets',
            'registerBackendSettings',
        ],
        \Cms\ServiceProvider::class => [
            'registerBackendNavigation',
            'registerBackendReportWidgets',
            'registerBackendWidgets',
            'registerBackendSettings',
        ],
        \System\ServiceProvider::class => [
            'registerBackendNavigation',
            'registerBackendReportWidgets',
            'registerBackendSettings',
        ],
    ];

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
     * Register Winter's back-end navigation, widgets and settings in a worker.
     *
     * The System, Backend and Cms service providers register these only when the current request is
     * a back-end request. A worker registers its providers once, against the request it boots with,
     * which is not a back-end request, so every back-end page it later serves would have empty menus
     * and settings. Each registration only stores a callback that runs on first use, so making them
     * after the providers have registered is cheap and has the same effect.
     *
     * This runs from a booting callback: after every provider has registered, and before any
     * provider or plugin boots. Core's callbacks therefore come before callbacks that plugins add
     * when they boot, and before anything lists form widgets, as they do under PHP-FPM.
     *
     * @return void
     */
    public function registerBackendExtensions(): void
    {
        if (!$this->app->bound('Laravel\Octane\Contracts\Client') || $this->app->runningInBackend()) {
            return;
        }

        foreach (static::BACKEND_REGISTRATIONS as $providerClass => $methods) {
            $provider = $this->app->getProvider($providerClass);

            if ($provider === null) {
                continue;
            }

            foreach ($methods as $method) {
                if (!method_exists($provider, $method)) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Winter.Octane: {$providerClass}::{$method}() no longer exists, so this worker "
                        . 'may be missing some back-end navigation, widgets or settings.'
                    );

                    continue;
                }

                (fn () => $this->{$method}())->call($provider);
            }
        }
    }

    /**
     * @return array<string, string>
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
            \Illuminate\Support\Facades\Log::warning(
                'Winter.Octane: this version of Storm cannot dispatch interface listeners, so Octane '
                . 'was not registered. Update winter/storm before serving through Octane.'
            );

            return;
        }

        $this->app->register(\Laravel\Octane\OctaneServiceProvider::class);

        /*
         * Replace Octane's server commands with the plugin's. octane:frankenphp defaults to a Caddyfile and a
         * worker file that suit Winter's layout, and octane:swoole and octane:roadrunner turn off the static
         * file serving that would otherwise serve .env and the rest of the project root. Octane's commands
         * are mapped lazily through their #[AsCommand] attributes, while these subclasses have none and are
         * added directly, and Symfony checks directly added commands first, so these answer to the names.
         */
        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                \Illuminate\Console\Application::starting(function ($artisan) {
                    $artisan->resolve(\Winter\Octane\Console\StartFrankenPhpCommand::class);
                    $artisan->resolve(\Winter\Octane\Console\StartRoadRunnerCommand::class);
                    $artisan->resolve(\Winter\Octane\Console\StartSwooleCommand::class);
                });
            });
        }

        $this->app->booting(function () {
            $this->registerBackendExtensions();
        });

        /*
         * The reset deliberately runs at the start of every operation rather than the end. An
         * exception that escapes the HTTP kernel skips ApplicationGateway::terminate(), so
         * RequestTerminated — and every listener registered against Octane's OperationTerminated
         * contract — never fires. Cleaning up on the way in is the only boundary that also holds
         * after a failed operation.
         *
         * The operation listeners are attached at the highest priority Storm's dispatcher accepts, so the
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
        ] as $event) {
            $this->app->make('events')->listen($event, [ResetsRequestState::class, 'handle'], PHP_INT_MAX);
        }

        /*
         * A failed operation is also cleaned up straight away, rather than leaving the worker dirty
         * until the next request. This one runs LAST, after Octane's ReportException listener, so the
         * exception is reported while the failed request's user, page and action are still set. The
         * next operation's reset still runs first, so a listener that halts propagation here cannot
         * leave state behind.
         */
        $this->app->make('events')->listen(
            \Laravel\Octane\Events\WorkerErrorOccurred::class,
            [ResetsRequestState::class, 'handle'],
            PHP_INT_MIN
        );

        /*
         * ResolveAuthIpAddress is appended to the global stack, so it runs after the trusted-proxy
         * middleware. ServePublicFiles is prepended, so a whitelisted file is returned before any other
         * middleware runs, as it is when a web server serves it.
         */
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            $kernel->prependMiddleware(ServePublicFiles::class);
            $kernel->pushMiddleware(ResolveAuthIpAddress::class);
        }
    }
}
