<?php namespace Winter\Octane\Classes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Winter\Storm\Halcyon\Datasource\FileDatasource;
use Winter\Storm\Halcyon\Datasource\Resolver;

/**
 * Prepares a worker when it starts, before it serves a request.
 *
 * Octane only dispatches WorkerStarting in a worker, so none of this runs under PHP-FPM. Each step only
 * makes requests cheaper, so a failure is logged and the worker carries on without that step.
 */
class WarmsWorker
{
    /**
     * The request attribute that is true on a warm-up request, so application code can tell one from a
     * visitor's request. A client cannot set a request attribute.
     */
    public const WARM_UP_ATTRIBUTE = 'winter.octane.warm_up';

    /**
     * Handle Octane's WorkerStarting event.
     *
     * @param \Laravel\Octane\Events\WorkerStarting $event
     * @return void
     */
    public function handle($event): void
    {
        foreach ([
            fn () => (new ResetsRequestState())->resolveTraitUsers(),
            fn () => $this->useWorkerDatasources($event->app),
        ] as $step) {
            try {
                $step();
            }
            catch (Throwable $ex) {
                $this->logQuietly($ex);
            }
        }
    }

    /**
     * Send the configured warm-up requests through the worker before it serves a real one.
     *
     * A worker's first request loads classes, compiles routes and builds whatever the application keeps
     * for the life of the worker. A server that prefers one worker leaves the others unused until
     * traffic rises, so they would pay for that at the busiest moment. Each path in
     * octane.warm_up_paths is requested here instead, as a GET from a visitor with no cookies.
     *
     * @param \Laravel\Octane\Events\WorkerStarting $event
     * @return void
     */
    public function sendWarmUpRequests($event): void
    {
        try {
            $config = $event->app->make('config');
            $paths = array_filter((array) $config->get('octane.warm_up_paths', []), 'is_string');
            $root = rtrim((string) $config->get('app.url', 'http://localhost'), '/');

            if ($paths === [] || !$this->startsBeforeItsFirstRequest($event->app)) {
                return;
            }
        }
        catch (Throwable $ex) {
            $this->logQuietly($ex);

            return;
        }

        foreach ($paths as $path) {
            $url = $root . '/' . ltrim($path, '/');

            try {
                $status = $this->sendWarmUpRequest($event->app, $url);

                if ($status >= 400) {
                    throw new \RuntimeException("it was answered with HTTP {$status}");
                }
            }
            catch (Throwable $ex) {
                $this->logQuietly(new \RuntimeException("the warm-up request for {$url} failed: " . $ex->getMessage(), 0, $ex));
            }
        }
    }

    /**
     * Whether this worker starts ahead of the requests it will serve, so a warm-up request costs no
     * visitor anything.
     *
     * RoadRunner's worker starts when its first request has already arrived, so a warm-up request there
     * would make that visitor wait for it. A Swoole task worker never serves a request.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return bool
     */
    protected function startsBeforeItsFirstRequest($app): bool
    {
        if ($app->make(\Laravel\Octane\Contracts\Client::class) instanceof \Laravel\Octane\RoadRunner\RoadRunnerClient) {
            return false;
        }

        return !$app->bound('Swoole\Http\Server') || !$app->make('Swoole\Http\Server')->taskworker;
    }

    /**
     * Send one warm-up request, discard its response and return its status.
     *
     * This follows Octane's Worker::handle(): the request runs in a sandbox cloned from the application,
     * through Octane's gateway, so it gets the reset, the middleware and the terminate step a real
     * request gets, and the reset at the start of the next request clears what it leaves behind. Octane's
     * Worker is not used because it would send the response to the server, and because it raises
     * WorkerErrorOccurred when a request fails, on which Octane stops a RoadRunner worker. A failed
     * warm-up request must not stop the worker.
     *
     * @param \Illuminate\Foundation\Application $app
     * @param string $url
     * @return int
     */
    protected function sendWarmUpRequest($app, string $url): int
    {
        $request = Request::create($url);
        $request->attributes->set(static::WARM_UP_ATTRIBUTE, true);

        $bufferLevel = ob_get_level();
        $bootRequest = $app->make('request');

        try {
            \Laravel\Octane\CurrentApplication::set($sandbox = clone $app);

            $gateway = new \Laravel\Octane\ApplicationGateway($app, $sandbox);

            ob_start();

            $response = $gateway->handle($request);
            $gateway->terminate($request, $response);

            return $response->getStatusCode();
        }
        finally {
            try {
                while (ob_get_level() > $bufferLevel && @ob_end_clean()) {
                    // Close the buffers the request left open.
                }

                /*
                 * Octane binds each request on the application as well as on its sandbox. Put back the
                 * request the worker booted with, there and on the router, and drop the guards' hold on this
                 * one, much as Worker::handle() does, so nothing that runs before the first visitor's request, or
                 * in a worker that never serves one, sees the warm-up request as the current one.
                 */
                $app->instance('request', $bootRequest);

                (fn () => $this->currentRequest = $bootRequest)->call($app->make('router'));

                if ($app->resolved('auth')) {
                    $app->make('auth')->forgetGuards();
                }
            }
            finally {
                /*
                 * Worker::handle() empties the sandbox here. Octane has handed that sandbox to the log,
                 * cache and other long-lived services, which keep it until the next request, and code
                 * can still run before then: the warning this class logs for a failed request, and the
                 * rest of the server's start-up. An emptied sandbox would fail them, so this one is
                 * left whole. The first request replaces it.
                 */
                \Laravel\Octane\CurrentApplication::set($app);
            }
        }
    }

    /**
     * Replace each exact FileDatasource in the resolver, which is normally a theme's, with a
     * WorkerFileDatasource, now and for any theme registered later. A theme's datasource is registered
     * once and held for the life of the worker.
     *
     * Only a datasource that is exactly a FileDatasource is replaced. A subclass, or the AutoDatasource
     * core builds for a child theme or for database templates, may depend on behavior this would change.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function useWorkerDatasources($app): void
    {
        if (!$app->bound('halcyon')) {
            return;
        }

        $resolver = $app->make('halcyon');

        if (!$resolver instanceof Resolver || !$this->coreSupportsWorkerDatasource()) {
            Log::warning('Winter.Octane: the Halcyon datasource classes have changed, so theme templates are read without the worker datasource.');

            return;
        }

        foreach ((new \ReflectionProperty(Resolver::class, 'datasources'))->getValue($resolver) as $name => $datasource) {
            $this->replaceDatasource($resolver, (string) $name, $datasource);
        }

        /*
         * This runs during a request, inside Theme::registerHalcyonDatasource(), so a failure must leave
         * the theme on the datasource core just registered rather than fail the request.
         */
        $app->make('events')->listen('cms.theme.registerHalcyonDatasource', function ($theme, $resolver) {
            try {
                $this->replaceDatasource($resolver, $theme->getDirName(), $resolver->datasource($theme->getDirName()));
            }
            catch (Throwable $ex) {
                $this->logQuietly($ex);
            }
        });
    }

    /**
     * Whether core still has the protected members WorkerFileDatasource and this class use.
     *
     * @return bool
     */
    protected function coreSupportsWorkerDatasource(): bool
    {
        return property_exists(Resolver::class, 'datasources')
            && property_exists(FileDatasource::class, 'basePath')
            && property_exists(FileDatasource::class, 'files')
            && property_exists(FileDatasource::class, 'maxDepth')
            && (new \ReflectionClass(FileDatasource::class))->hasMethod('makeFilePath');
    }

    /**
     * Replace one datasource, when it is exactly a FileDatasource.
     *
     * @param \Winter\Storm\Halcyon\Datasource\Resolver $resolver
     * @param string $name
     * @param object $datasource
     * @return void
     */
    protected function replaceDatasource(Resolver $resolver, string $name, object $datasource): void
    {
        if (get_class($datasource) === FileDatasource::class) {
            $resolver->addDatasource($name, WorkerFileDatasource::fromFileDatasource($datasource));
        }
    }

    /**
     * Log a failure without letting a broken log channel stop the worker or the request.
     *
     * @param \Throwable $ex
     * @return void
     */
    protected function logQuietly(Throwable $ex): void
    {
        try {
            Log::warning('Winter.Octane: preparing the worker failed, so it runs without that optimization: ' . $ex->getMessage(), ['exception' => $ex]);
        }
        catch (Throwable $logFailure) {
            // The log channel itself is broken; there is nowhere safe left to report to.
        }
    }
}
