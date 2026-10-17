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
 *
 * Octane also reads each line FrankenPHP writes to stderr as a Caddy log entry and prints its
 * "msg" field. An application that logs to stderr through Monolog's JsonFormatter writes records
 * with a "message" field instead, which Octane printed as "unknown error". This command prints
 * those records unchanged and leaves every other line to Octane.
 */
class StartFrankenPhpCommand extends OctaneStartFrankenPhpCommand
{
    /**
     * @var string Error output after the last newline, held until the rest of that line arrives.
     */
    protected string $pendingErrorOutput = '';

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
     * Determine whether a stderr line is a Monolog JSON record rather than a Caddy or Octane message.
     *
     * @param string $line
     * @return bool
     */
    public static function isApplicationLogLine(string $line): bool
    {
        $record = json_decode($line, true);

        return is_array($record)
            && !array_key_exists('msg', $record)
            && array_key_exists('message', $record)
            && array_key_exists('level_name', $record);
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

    /**
     * Return the server's new output, after printing the Monolog records in its error output.
     *
     * Octane reads the pipe without waiting, so a long record can arrive in two reads. An
     * unfinished last line is held back until the rest of it arrives, or until the server stops.
     *
     * @param \Symfony\Component\Process\Process $server
     * @return array{0: string, 1: string}
     */
    protected function getServerOutput($server)
    {
        [$output, $errorOutput] = parent::getServerOutput($server);

        $text = $this->pendingErrorOutput . $errorOutput;
        $cut = strrpos($text, "\n");

        if ($server->isRunning() && $cut !== false) {
            $this->pendingErrorOutput = substr($text, $cut + 1);
            $text = substr($text, 0, $cut);
        }
        elseif ($server->isRunning()) {
            $this->pendingErrorOutput = $text;
            $text = '';
        }
        else {
            $this->pendingErrorOutput = '';
        }

        $serverLines = [];

        foreach (explode("\n", $text) as $line) {
            if (static::isApplicationLogLine($line)) {
                $this->raw($line);
            }
            else {
                $serverLines[] = $line;
            }
        }

        return [$output, implode("\n", $serverLines)];
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
