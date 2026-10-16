<?php

namespace Winter\Octane\Tests;

use Illuminate\Contracts\Console\Kernel;
use Laravel\Octane\Commands\Concerns\InstallsFrankenPhpDependencies;
use Laravel\Octane\Commands\StartFrankenPhpCommand as OctaneStartFrankenPhpCommand;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Octane\Console\StartFrankenPhpCommand;

/**
 * The plugin's octane:frankenphp defaults to a Caddyfile and a worker file that suit Winter's
 * layout, while still honouring an explicit --caddyfile.
 */
class StartFrankenPhpCommandTest extends PluginTestCase
{
    /**
     * @var string Worker file path the command under test writes to, inside a temporary directory.
     */
    protected string $worker = '';

    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(OctaneStartFrankenPhpCommand::class)) {
            $this->markTestSkipped('laravel/octane is not installed.');
        }

        $this->worker = sys_get_temp_dir() . '/winter-octane-' . bin2hex(random_bytes(6)) . '/frankenphp-worker.php';
        mkdir(dirname($this->worker));
    }

    public function tearDown(): void
    {
        if ($this->worker !== '') {
            @unlink($this->worker);
            @rmdir(dirname($this->worker));
        }

        parent::tearDown();
    }

    public function testOctaneFrankenPhpResolvesToThePluginCommand()
    {
        $command = $this->app->make(Kernel::class)->all()['octane:frankenphp'];

        $this->assertInstanceOf(StartFrankenPhpCommand::class, $command);
    }

    public function testDefaultsToThePluginCaddyfile()
    {
        $this->assertSame(
            realpath(dirname(__DIR__) . '/caddy/Caddyfile'),
            $this->callConfigPath([])
        );
    }

    public function testHonoursAnExplicitCaddyfile()
    {
        $custom = tempnam(sys_get_temp_dir(), 'caddy-');

        try {
            $this->assertSame(realpath($custom), $this->callConfigPath(['--caddyfile' => $custom]));
        }
        finally {
            unlink($custom);
        }
    }

    public function testInstallsTheWinterWorkerOnlyWhenNoneExists()
    {
        $this->makeCommand([])->ensureFrankenPhpWorkerIsInstalled();

        $this->assertFileEquals(dirname(__DIR__) . '/caddy/frankenphp-worker.php', $this->worker);
        $this->assertStringContainsString('/bootstrap/autoload.php', (string) file_get_contents($this->worker));

        file_put_contents($this->worker, '<?php // site-specific');
        $this->makeCommand([])->ensureFrankenPhpWorkerIsInstalled();

        $this->assertSame(
            '<?php // site-specific',
            file_get_contents($this->worker),
            'an existing worker file must be kept'
        );
    }

    public function testReplacesOctanesUnmodifiedWorkerStub()
    {
        $stub = dirname((string) (new ReflectionClass(InstallsFrankenPhpDependencies::class))->getFileName())
            . '/../stubs/frankenphp-worker.php';
        copy($stub, $this->worker);

        $this->makeCommand([])->ensureFrankenPhpWorkerIsInstalled();

        $this->assertFileEquals(dirname(__DIR__) . '/caddy/frankenphp-worker.php', $this->worker);
    }

    /**
     * Guards against the plugin's Caddyfile being replaced by a copy of Octane's stub. The E2E test
     * checks what the server actually serves.
     */
    public function testTheCaddyfileSendsEverythingButWhitelistedAssetsToTheWorker()
    {
        $caddyfile = (string) file_get_contents(dirname(__DIR__) . '/caddy/Caddyfile');

        $this->assertStringContainsString('rewrite * /frankenphp-worker.php', $caddyfile);
        $this->assertStringContainsString('file_server off', $caddyfile);
        $this->assertStringNotContainsString('try_files {path}', $caddyfile, 'Octane\'s stub serves any existing file');
    }

    /**
     * @param array<string, string> $options
     */
    protected function makeCommand(array $options): StartFrankenPhpCommand
    {
        $command = new class ($this->worker) extends StartFrankenPhpCommand {
            public function __construct(protected string $testWorkerPath)
            {
                parent::__construct();
            }

            protected function workerPath(): string
            {
                return $this->testWorkerPath;
            }
        };
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput($options, $command->getDefinition()));

        return $command;
    }

    /**
     * @param array<string, string> $options
     */
    protected function callConfigPath(array $options): string
    {
        $command = $this->makeCommand($options);

        return (fn () => $this->configPath())->call($command);
    }
}
