<?php namespace Winter\Octane\Console;

use Laravel\Octane\Commands\StartRoadRunnerCommand as OctaneStartRoadRunnerCommand;
use Laravel\Octane\RoadRunner\ServerProcessInspector;
use Laravel\Octane\RoadRunner\ServerStateFile;
use ReflectionClass;

/**
 * Starts Octane's RoadRunner server without RoadRunner's static file middleware.
 *
 * Octane points RoadRunner's static middleware at public_path(), which in Winter is the project
 * root, so Octane's own command would serve .env, composer.json, the logs, the SQLite database and
 * the source of every PHP file. The middleware can only filter by file extension, not by path, so
 * this command removes it, and the plugin's ServePublicFiles middleware serves the whitelisted
 * files from the worker instead.
 */
class StartRoadRunnerCommand extends OctaneStartRoadRunnerCommand
{
    /**
     * The middleware RoadRunner is given when static was the only one configured. RoadRunner
     * refuses to start with an empty list. Compression matches the plugin's Caddyfile, which also
     * compresses responses.
     */
    public const FALLBACK_MIDDLEWARE = 'gzip';

    /**
     * @param \Laravel\Octane\RoadRunner\ServerProcessInspector $inspector
     * @param \Laravel\Octane\RoadRunner\ServerStateFile $serverStateFile
     * @return int
     */
    public function handle(ServerProcessInspector $inspector, ServerStateFile $serverStateFile)
    {
        if (!static::octaneReadsMiddlewareSetting()) {
            $this->components->error(
                'This version of Laravel Octane no longer reads octane.roadrunner.http_middleware, so Winter.Octane '
                . 'cannot stop RoadRunner serving files such as .env from the project root. The server was not started.'
            );

            return 1;
        }

        $config = $this->laravel->make('config');
        $config->set(
            'octane.roadrunner.http_middleware',
            static::withoutStaticMiddleware((string) $config->get('octane.roadrunner.http_middleware', 'static'))
        );

        return parent::handle($inspector, $serverStateFile);
    }

    /**
     * Whether Octane's command still builds RoadRunner's middleware list from the setting this
     * command changes.
     *
     * Octane passes the list inline when it starts the server, so there is no method to override.
     * Reading its source is the only way to know the setting still applies, and a release that
     * stops reading it would otherwise bring the static middleware back unnoticed.
     *
     * @return bool
     */
    public static function octaneReadsMiddlewareSetting(): bool
    {
        $file = (new ReflectionClass(OctaneStartRoadRunnerCommand::class))->getFileName();
        $source = $file === false ? '' : (string) file_get_contents($file);

        return str_contains($source, "'http.middleware='.config('octane.roadrunner.http_middleware'");
    }

    /**
     * A RoadRunner middleware list with the static middleware removed.
     *
     * @param string $middleware Comma-separated middleware names, as Octane passes them to RoadRunner.
     * @return string
     */
    public static function withoutStaticMiddleware(string $middleware): string
    {
        $kept = array_values(array_filter(
            array_map('trim', explode(',', $middleware)),
            fn (string $name) => $name !== '' && $name !== 'static'
        ));

        return $kept === [] ? static::FALLBACK_MIDDLEWARE : implode(',', $kept);
    }
}
