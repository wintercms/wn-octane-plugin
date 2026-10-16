<?php namespace Winter\Octane\Console;

use Laravel\Octane\Commands\Concerns\InstallsFrankenPhpDependencies;
use Laravel\Octane\Commands\StartFrankenPhpCommand as OctaneStartFrankenPhpCommand;
use ReflectionClass;

/**
 * Starts Octane's FrankenPHP server with defaults that are safe for Winter.
 *
 * Octane assumes a Laravel layout, where the web root is a public/ directory holding only public
 * files. Winter serves from the project root, so Octane's own Caddyfile would serve composer.json,
 * .env and the logs as files, and execute any PHP file in the project directly. Octane's worker
 * stub also looks for the application one directory above the web root. This command uses the
 * plugin's Caddyfile unless --caddyfile is given, and installs a worker file with Winter's paths.
 */
class StartFrankenPhpCommand extends OctaneStartFrankenPhpCommand
{
    /**
     * Install the plugin's worker file when there is none, or when the existing one is Octane's
     * unmodified stub (written by octane:install), which cannot boot Winter.
     *
     * @return void
     */
    public function ensureFrankenPhpWorkerIsInstalled()
    {
        $worker = $this->workerPath();

        if (file_exists($worker) && !$this->isOctaneWorkerStub($worker)) {
            return;
        }

        copy(dirname(__DIR__) . '/caddy/frankenphp-worker.php', $worker);
    }

    /**
     * @return string
     */
    protected function configPath()
    {
        if (!$this->option('caddyfile')) {
            $this->input->setOption('caddyfile', dirname(__DIR__) . '/caddy/Caddyfile');
        }

        return parent::configPath();
    }

    protected function isOctaneWorkerStub(string $worker): bool
    {
        $stub = dirname((string) (new ReflectionClass(InstallsFrankenPhpDependencies::class))->getFileName())
            . '/../stubs/frankenphp-worker.php';

        return is_file($stub) && file_get_contents($worker) === file_get_contents($stub);
    }

    protected function workerPath(): string
    {
        return public_path('frankenphp-worker.php');
    }
}
