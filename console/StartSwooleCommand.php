<?php namespace Winter\Octane\Console;

use Illuminate\Http\Request;
use Laravel\Octane\Commands\StartSwooleCommand as OctaneStartSwooleCommand;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\ServerProcessInspector;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleClient;
use Laravel\Octane\Swoole\SwooleExtension;

/**
 * Starts Octane's Swoole server without its static file serving.
 *
 * Octane's Swoole client serves any file under public_path() that is not a PHP file, and Winter's
 * public_path() is the project root, so Octane's own command would serve .env, composer.json, the
 * logs and the SQLite database to anyone who asks. Swoole cannot limit this to Winter's whitelist,
 * so this command turns it off, and the plugin's ServePublicFiles middleware serves the whitelisted
 * files from the worker instead.
 */
class StartSwooleCommand extends OctaneStartSwooleCommand
{
    /**
     * @param \Laravel\Octane\Swoole\ServerProcessInspector $inspector
     * @param \Laravel\Octane\Swoole\ServerStateFile $serverStateFile
     * @param \Laravel\Octane\Swoole\SwooleExtension $extension
     * @return int
     */
    public function handle(
        ServerProcessInspector $inspector,
        ServerStateFile $serverStateFile,
        SwooleExtension $extension
    ) {
        $this->disableStaticFiles();

        if ($this->servesStaticFiles()) {
            $this->components->error(
                'This version of Laravel Octane serves files from the project root that Winter.Octane cannot turn off, '
                . 'including .env. The server was not started.'
            );

            return 1;
        }

        return parent::handle($inspector, $serverStateFile, $extension);
    }

    /**
     * Turn off the Swoole client's static file serving.
     *
     * Octane writes the octane configuration into the server state file that the Swoole server
     * process reads, so the change has to be made before the parent command writes it.
     *
     * @return void
     */
    public function disableStaticFiles(): void
    {
        $this->laravel->make('config')->set('octane.serve_static_files', false);
    }

    /**
     * Whether Octane's Swoole client would still serve composer.json, given the state the server
     * process is started with.
     *
     * A check of Octane's behavior rather than of the setting's name, so a release that renames or
     * ignores the setting stops the server instead of serving .env. Swoole's own static handler,
     * which a site can turn on through octane.swoole.options, serves the same files before Octane
     * sees the request, so it counts too.
     *
     * @return bool
     */
    public function servesStaticFiles(): bool
    {
        if ($this->laravel->make('config')->get('octane.swoole.options.enable_static_handler')) {
            return true;
        }

        $context = new RequestContext([
            'publicPath' => public_path(),
            'octaneConfig' => $this->laravel->make('config')->get('octane'),
        ]);

        return (new SwooleClient())->canServeRequestAsStaticFile(Request::create('/composer.json'), $context);
    }
}
