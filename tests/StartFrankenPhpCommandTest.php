<?php

namespace Winter\Octane\Tests;

use Illuminate\Contracts\Console\Kernel;
use Laravel\Octane\Commands\Concerns\InstallsFrankenPhpDependencies;
use Laravel\Octane\Commands\StartFrankenPhpCommand as OctaneStartFrankenPhpCommand;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Octane\Console\StartFrankenPhpCommand;

/**
 * The plugin's octane:frankenphp defaults to a Caddyfile and a worker file that suit Winter's
 * layout, while still honoring an explicit --caddyfile.
 */
class StartFrankenPhpCommandTest extends PluginTestCase
{
    /**
     * @var string Worker file path the command under test writes to, inside a temporary directory.
     */
    protected string $worker = '';

    /**
     * Skips when laravel/octane is missing, and creates an empty temporary directory for the worker
     * file.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(OctaneStartFrankenPhpCommand::class)) {
            $this->markTestSkipped('laravel/octane is not installed.');
        }

        $this->worker = sys_get_temp_dir() . '/winter-octane-' . bin2hex(random_bytes(6)) . '/frankenphp-worker.php';
        mkdir(dirname($this->worker));
    }

    /**
     * Removes the temporary worker file and its directory.
     *
     * @return void
     */
    public function tearDown(): void
    {
        if ($this->worker !== '') {
            @unlink($this->worker);
            @rmdir(dirname($this->worker));
        }

        parent::tearDown();
    }

    /**
     * The octane:frankenphp command name resolves to the plugin's command, not Octane's.
     *
     * @return void
     */
    public function testOctaneFrankenPhpResolvesToThePluginCommand(): void
    {
        $command = $this->app->make(Kernel::class)->all()['octane:frankenphp'];

        $this->assertInstanceOf(StartFrankenPhpCommand::class, $command);
    }

    /**
     * Without --caddyfile, the command uses the Caddyfile that ships with the plugin.
     *
     * @return void
     */
    public function testDefaultsToThePluginCaddyfile(): void
    {
        $this->assertSame(
            realpath(dirname(__DIR__) . '/caddy/Caddyfile'),
            $this->callConfigPath([])
        );
    }

    /**
     * An explicit --caddyfile is used instead of the plugin's Caddyfile.
     *
     * @return void
     */
    public function testHonoursAnExplicitCaddyfile(): void
    {
        $custom = tempnam(sys_get_temp_dir(), 'caddy-');

        try {
            $this->assertSame(realpath($custom), $this->callConfigPath(['--caddyfile' => $custom]));
        }
        finally {
            unlink($custom);
        }
    }

    /**
     * The command writes the plugin's worker file when none exists, and keeps a site's own worker
     * file on the next run.
     *
     * @return void
     */
    public function testInstallsTheWinterWorkerOnlyWhenNoneExists(): void
    {
        $this->makeCommand([])->ensureFrankenPhpWorkerIsInstalled();

        $this->assertFileEquals(dirname(__DIR__) . '/caddy/frankenphp-worker.php', $this->worker);
        $this->assertStringContainsString('/bootstrap/autoload.php', (string) file_get_contents($this->worker));

        // Stand in for a worker file the site has customized.
        file_put_contents($this->worker, '<?php // site-specific');
        $this->makeCommand([])->ensureFrankenPhpWorkerIsInstalled();

        $this->assertSame(
            '<?php // site-specific',
            file_get_contents($this->worker),
            'an existing worker file must be kept'
        );
    }

    /**
     * An unmodified copy of Octane's worker stub is replaced with the plugin's worker file, since the
     * stub does not load Winter's bootstrap.
     *
     * @return void
     */
    public function testReplacesOctanesUnmodifiedWorkerStub(): void
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
     *
     * @return void
     */
    public function testTheCaddyfileSendsEverythingButWhitelistedAssetsToTheWorker(): void
    {
        $caddyfile = (string) file_get_contents(dirname(__DIR__) . '/caddy/Caddyfile');

        $this->assertStringContainsString('rewrite * /frankenphp-worker.php', $caddyfile);
        $this->assertStringContainsString('file_server off', $caddyfile);
        $this->assertStringNotContainsString('try_files {path}', $caddyfile, 'Octane\'s stub serves any existing file');
    }

    /**
     * Monolog JSON records in the server's error output are printed unchanged, and every other line
     * (Caddy's own log lines and plain text) is left for Octane to format.
     *
     * @return void
     */
    public function testMonologRecordsArePrintedAndOtherLinesGoToOctane(): void
    {
        $command = $this->makeCommand([]);
        $buffer = new BufferedOutput();

        // Capture what the command prints directly, which is where a Monolog record goes.
        (fn () => $this->output = $buffer)->call($command);

        $caddy = '{"level":"error","logger":"http.log.access","msg":"handled request"}';
        $record = '{"message":"Import failed","context":{},"level":400,"level_name":"ERROR","channel":"prod"}';
        $stream = '{"level":"error","msg":"{\\"type\\":\\"shutdown\\"}"}';

        [$output, $errorOutput] = $this->callGetServerOutput(
            $command,
            $this->fakeServer(implode("\n", [$caddy, $record, 'plain text', $stream]), false)
        );

        $this->assertSame($record . "\n", $buffer->fetch());
        $this->assertSame('stdout text', $output);
        $this->assertSame(implode("\n", [$caddy, 'plain text', $stream]), $errorOutput);
    }

    /**
     * A record that arrives across two reads is held until its line ends, then printed once, whole.
     * A partial line still held when the server stops is passed to Octane.
     *
     * @return void
     */
    public function testARecordSplitAcrossReadsIsPrintedOnceWhole(): void
    {
        $command = $this->makeCommand([]);
        $buffer = new BufferedOutput();
        (fn () => $this->output = $buffer)->call($command);

        $record = '{"message":"Import failed","context":{},"level":400,"level_name":"ERROR","channel":"prod"}';
        $half = intdiv(strlen($record), 2);

        $first = $this->callGetServerOutput($command, $this->fakeServer(substr($record, 0, $half), true));
        $second = $this->callGetServerOutput($command, $this->fakeServer(substr($record, $half) . "\n", true));

        $this->assertSame('', $first[1]);
        $this->assertSame('', $second[1]);
        $this->assertSame($record . "\n", $buffer->fetch());

        // Hold a partial line, then stop the server: the held text must still reach Octane.
        $this->callGetServerOutput($command, $this->fakeServer('partial', true));
        $last = $this->callGetServerOutput($command, $this->fakeServer(' line', false));

        $this->assertSame('partial line', $last[1], 'a partial line left when the server stops goes to Octane');
    }

    /**
     * Error output read after the server has stopped is joined to the held half of a record, so the
     * record is printed whole instead of being flushed as a fragment.
     *
     * @return void
     */
    public function testOutputWrittenJustBeforeTheServerExitsCompletesAHeldRecord(): void
    {
        $command = $this->makeCommand([]);
        $buffer = new BufferedOutput();
        (fn () => $this->output = $buffer)->call($command);

        $record = '{"message":"Shutdown failed","context":{},"level":400,"level_name":"ERROR","channel":"prod"}';
        $half = intdiv(strlen($record), 2);

        $this->callGetServerOutput($command, $this->fakeServer(substr($record, 0, $half), true));
        $last = $this->callGetServerOutput(
            $command,
            $this->fakeServer('', false, substr($record, $half) . "\n")
        );

        $this->assertSame($record . "\n", $buffer->fetch());
        $this->assertSame('', $last[1]);
    }

    /**
     * Only a JSON object with Monolog's message and level_name keys, and no Caddy msg key, counts as
     * an application log line.
     *
     * @return void
     */
    public function testOnlyMonologRecordsCountAsApplicationLogLines(): void
    {
        $this->assertTrue(StartFrankenPhpCommand::isApplicationLogLine('{"message":"x","level_name":"ERROR"}'));
        $this->assertFalse(StartFrankenPhpCommand::isApplicationLogLine('{"level":"error","msg":"handled request"}'));
        $this->assertFalse(StartFrankenPhpCommand::isApplicationLogLine('{"message":"x","level_name":"ERROR","msg":"y"}'));
        $this->assertFalse(StartFrankenPhpCommand::isApplicationLogLine('{"message":"no level"}'));
        $this->assertFalse(StartFrankenPhpCommand::isApplicationLogLine('plain text'));
        $this->assertFalse(StartFrankenPhpCommand::isApplicationLogLine(''));
    }

    /**
     * Calls the command's protected getServerOutput() against a fake server.
     *
     * @param \Winter\Octane\Console\StartFrankenPhpCommand $command
     * @param \Symfony\Component\Process\Process $server
     * @return array{0: string, 1: string} The output and the error output left for Octane.
     */
    protected function callGetServerOutput(StartFrankenPhpCommand $command, Process $server): array
    {
        return (fn () => $this->getServerOutput($server))->call($command);
    }

    /**
     * A stub server process that returns the given error output on the first read and
     * $finalErrorOutput on the next one.
     *
     * @param string $errorOutput Error output for the first read.
     * @param bool $running Whether the process reports itself as still running.
     * @param string $finalErrorOutput Error output for the read made after the first.
     * @return \Symfony\Component\Process\Process
     */
    protected function fakeServer(string $errorOutput, bool $running, string $finalErrorOutput = ''): Process
    {
        $server = $this->createStub(Process::class);
        $server->method('isRunning')->willReturn($running);
        $server->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('stdout text', '');
        $server->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls($errorOutput, $finalErrorOutput);
        $server->method('clearOutput')->willReturnSelf();
        $server->method('clearErrorOutput')->willReturnSelf();

        return $server;
    }

    /**
     * Builds the plugin's command with the given options, writing its worker file to the test's
     * temporary path instead of the site root.
     *
     * @param array<string, string> $options
     * @return \Winter\Octane\Console\StartFrankenPhpCommand
     */
    protected function makeCommand(array $options): StartFrankenPhpCommand
    {
        $command = new class ($this->worker) extends StartFrankenPhpCommand {
            /**
             * @param string $testWorkerPath Where the worker file is written.
             */
            public function __construct(protected string $testWorkerPath)
            {
                parent::__construct();
            }

            /**
             * Points the command at the test's temporary worker file.
             *
             * @return string
             */
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
     * Calls the command's protected configPath() with the given options.
     *
     * @param array<string, string> $options
     * @return string The Caddyfile path the command would use.
     */
    protected function callConfigPath(array $options): string
    {
        $command = $this->makeCommand($options);

        return (fn () => $this->configPath())->call($command);
    }
}
