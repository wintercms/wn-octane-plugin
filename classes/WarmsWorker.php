<?php namespace Winter\Octane\Classes;

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
