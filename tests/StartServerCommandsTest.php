<?php

namespace Winter\Octane\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\SwooleClient;
use Laravel\Octane\Swoole\SwooleExtension;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Octane\Console\StartRoadRunnerCommand;
use Winter\Octane\Console\StartSwooleCommand;

/**
 * Stands in for a RoadRunner installation without the spiral packages, so the command stops right
 * after the plugin's changes, before it would download or start RoadRunner.
 */
class RoadRunnerCommandWithoutPackages extends StartRoadRunnerCommand
{
    /**
     * @return bool
     */
    protected function isRoadRunnerInstalled()
    {
        return false;
    }
}

/**
 * The plugin's octane:swoole and octane:roadrunner start the servers without the static file
 * serving that would serve .env and the rest of Winter's project root.
 */
class StartServerCommandsTest extends PluginTestCase
{
    use ReportsDeprecationsToPhpUnit;

    /**
     * Files in every Winter installation that must never be served, as request paths.
     */
    protected const PRIVATE_FILES = ['/.env', '/composer.json', '/config/app.php', '/index.php'];

    /**
     * A log file created for the test, as a request path.
     *
     * @var string
     */
    protected string $log = '/storage/logs/winter-octane-static-files.log';

    /**
     * Skips when laravel/octane is missing, and creates the log file.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(SwooleClient::class)) {
            $this->markTestSkipped('laravel/octane is not installed.');
        }

        file_put_contents(base_path($this->log), 'a log line');
    }

    /**
     * Removes the log file.
     *
     * @return void
     */
    public function tearDown(): void
    {
        @unlink(base_path($this->log));

        parent::tearDown();
    }

    /**
     * The octane:swoole and octane:roadrunner command names resolve to the plugin's commands.
     *
     * @return void
     */
    public function testTheServerCommandsResolveToThePluginCommands(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertInstanceOf(StartSwooleCommand::class, $commands['octane:swoole']);
        $this->assertInstanceOf(StartRoadRunnerCommand::class, $commands['octane:roadrunner']);
    }

    /**
     * Octane's Swoole client serves no file from the project root to a server started by the plugin's
     * command, where it serves them to one started by Octane's.
     *
     * @return void
     */
    public function testTheSwooleServerServesNoFilesFromTheProjectRoot(): void
    {
        $paths = array_merge(static::PRIVATE_FILES, [$this->log, '/modules/system/assets/css/styles.css']);

        $this->assertNotSame([], array_filter($paths, fn ($path) => $this->swooleServes($path)), 'Octane\'s own command serves these files.');

        $command = new StartSwooleCommand();
        $this->app->instance(SwooleExtension::class, new class extends SwooleExtension
        {
            public function isInstalled(): bool
            {
                return false;
            }
        });

        $this->assertSame(1, $this->runCommand($command), 'The command should stop at the missing extension.');
        $this->assertSame([], array_values(array_filter($paths, fn ($path) => $this->swooleServes($path))));
        $this->assertFalse($command->servesStaticFiles());
    }

    /**
     * The Swoole command's own check reports Octane's static file serving until it is turned off.
     *
     * @return void
     */
    public function testTheSwooleCommandDetectsStaticFileServing(): void
    {
        $command = new StartSwooleCommand();
        $command->setLaravel($this->app);

        $this->assertTrue($command->servesStaticFiles());

        $command->disableStaticFiles();

        $this->assertFalse($command->servesStaticFiles());
    }

    /**
     * Swoole's own static handler, turned on through octane.swoole.options, also counts as serving
     * files, because it serves the project root before Octane sees the request.
     *
     * @return void
     */
    public function testTheSwooleCommandDetectsSwoolesStaticHandler(): void
    {
        $this->app['config']->set('octane.swoole.options.enable_static_handler', true);

        $command = new StartSwooleCommand();
        $command->setLaravel($this->app);
        $command->disableStaticFiles();

        $this->assertTrue($command->servesStaticFiles());
    }

    /**
     * RoadRunner is started without its static middleware, and keeps any other middleware.
     *
     * @return void
     */
    public function testTheRoadRunnerServerIsStartedWithoutTheStaticMiddleware(): void
    {
        $config = $this->app->make('config');

        $config->set('octane.roadrunner.http_middleware', null);
        $this->assertSame(1, $this->runCommand(new RoadRunnerCommandWithoutPackages()));
        $this->assertSame('gzip', $config->get('octane.roadrunner.http_middleware'));

        $config->set('octane.roadrunner.http_middleware', 'static, headers,gzip');
        $this->assertSame(1, $this->runCommand(new RoadRunnerCommandWithoutPackages()));
        $this->assertSame('headers,gzip', $config->get('octane.roadrunner.http_middleware'));
    }

    /**
     * Octane's octane:roadrunner still builds RoadRunner's middleware list from the setting the
     * plugin's command changes.
     *
     * @return void
     */
    public function testOctaneStillReadsTheRoadRunnerMiddlewareSetting(): void
    {
        $this->assertTrue(StartRoadRunnerCommand::octaneReadsMiddlewareSetting());
    }

    /**
     * Run a command against this application, and return its exit code.
     *
     * @param \Illuminate\Console\Command $command
     * @return int
     */
    protected function runCommand(\Illuminate\Console\Command $command): int
    {
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput([]), new BufferedOutput());
    }

    /**
     * Whether Octane's Swoole client serves a request path, given the configuration the Swoole server
     * process would be started with.
     *
     * @param string $path
     * @return bool
     */
    protected function swooleServes(string $path): bool
    {
        $context = new RequestContext([
            'publicPath' => public_path(),
            'octaneConfig' => $this->app->make('config')->get('octane'),
        ]);

        return (new SwooleClient())->canServeRequestAsStaticFile(Request::create($path), $context);
    }
}
