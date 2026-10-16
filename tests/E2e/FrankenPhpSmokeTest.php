<?php

namespace Winter\Octane\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Serves the surrounding Winter installation through a REAL Octane server and checks that state
 * planted by one HTTP request is gone by the next.
 *
 * Everything else in this suite runs in-process. This test is the end of the chain: it starts
 * `php artisan octane:start --server=frankenphp` with one worker, plants state through a real HTTP
 * request (a Twig include gate, a shared view variable, a CMS code-parser cache entry), and reads
 * it back through a second request served by the same worker process. A leak here is a leak in
 * production, with no test double anywhere in the path.
 *
 * The diagnostic routes come from the plugin's routes.php and only exist when WINTER_OCTANE_SMOKE
 * is set, which this test sets for the server process it spawns.
 *
 * Opt-in: set WINTER_OCTANE_E2E=1 (CI does). FrankenPHP's binary is downloaded by Octane on first
 * start, so the first run needs the network; the test skips, with the server log attached, when
 * the server cannot come up.
 */
#[Group('e2e')]
class FrankenPhpSmokeTest extends TestCase
{
    /**
     * @var resource|null The octane:start process.
     */
    protected $serverProcess = null;

    /**
     * @var string Absolute path to the Winter installation the plugin sits in.
     */
    protected string $winterRoot = '';

    /**
     * @var int Port the server was started on.
     */
    protected int $port = 0;

    /**
     * @var string Path to the server's combined output log for this run.
     */
    protected string $serverLog = '';

    /**
     * @var string|null Where the site's own worker file was moved while the test runs.
     */
    protected ?string $movedWorker = null;

    public function setUp(): void
    {
        parent::setUp();

        if (getenv('WINTER_OCTANE_E2E') !== '1') {
            $this->markTestSkipped('End-to-end test is opt-in; set WINTER_OCTANE_E2E=1 to run it.');
        }

        $this->winterRoot = realpath(__DIR__ . '/../../../../..');

        if ($this->winterRoot === false || !file_exists($this->winterRoot . '/.env')) {
            $this->markTestSkipped('No configured Winter installation (.env) surrounds the plugin.');
        }
    }

    public function tearDown(): void
    {
        $this->stopServer();
        $this->restoreWorker();

        parent::tearDown();
    }

    public function testStatePlantedByOneRequestIsGoneByTheNext()
    {
        $this->startServer();

        $baseline = $this->get('/_octane-smoke/read');

        $this->assertFalse($baseline['twig_gate_open'], 'the include gate should start closed');
        $this->assertFalse($baseline['view_leak_present'], 'no smoke share should exist yet');
        $this->assertFalse($baseline['code_parser_dirty'], 'the parser cache should start clean');

        $planted = $this->get('/_octane-smoke/plant');
        $this->assertTrue($planted['planted']);

        $after = $this->get('/_octane-smoke/read');

        $this->assertSame(
            $baseline['pid'],
            $after['pid'],
            'the follow-up request was served by a different worker process, so this run proves '
            . 'nothing about cross-request state; with --workers=1 that means the worker crashed'
        );

        $this->assertFalse(
            $after['twig_gate_open'],
            'the Twig include gate planted by the previous request survived into this one'
        );
        $this->assertFalse(
            $after['view_leak_present'],
            'the view share planted by the previous request survived into this one'
        );
        $this->assertFalse(
            $after['code_parser_dirty'],
            'the code parser cache planted by the previous request survived into this one'
        );
    }

    /**
     * Winter serves from the project root, so the server must only serve Winter's whitelisted asset
     * paths as files. Octane's own Caddyfile served any file and ran any PHP file in the project.
     */
    public function testOnlyWhitelistedFilesAreServed()
    {
        $this->startServer();

        /*
         * A path that does not exist shows how the application answers. A sensitive path must get the
         * same status, which an executed PHP file (200 or 500) or a served file (200) would not.
         */
        [$notFoundStatus] = $this->fetch('/winter-octane-no-such-page');

        $leaks = [
            '/.env' => 'APP_KEY',
            '/composer.json' => '"require"',
            '/artisan' => 'Artisan',
            '/config/app.php' => 'Fatal error',
            '/plugins/winter/octane/Plugin.php' => 'Fatal error',
            '/modules/system/assets/../../../.env' => 'APP_KEY',
            '/modules/system/assets/%2e%2e/%2e%2e/%2e%2e/.env' => 'APP_KEY',
            '/modules/system/assets/..%2f..%2f..%2fcomposer.json' => '"require"',
            '/storage/app/media/../../../composer.json' => '"require"',
            '/modules/system/assets/../../../config/app.php' => 'Fatal error',
        ];

        foreach ($leaks as $path => $marker) {
            [$status, $body] = $this->fetchRaw($path);
            $message = "{$path} was served or executed directly instead of going to the application";

            $this->assertSame($notFoundStatus, $status, $message);
            $this->assertStringNotContainsString($marker, (string) $body, $message);
        }

        [$status, $body] = $this->fetch('/modules/system/assets/js/framework.js');
        $this->assertSame(200, $status, 'module assets must still be served as files');
        $this->assertStringContainsString('function', (string) $body);
    }

    /**
     * Start octane:start with a single FrankenPHP worker and wait until it accepts connections.
     */
    protected function startServer(): void
    {
        $this->ensureWorkerStubSuitsWinter();

        $this->port = $this->findFreePort();
        $this->serverLog = tempnam(sys_get_temp_dir(), 'octane-smoke-') . '.log';

        $command = [
            PHP_BINARY,
            'artisan',
            'octane:start',
            '--server=frankenphp',
            '--host=127.0.0.1',
            '--port=' . $this->port,
            '--workers=1',
            '--no-interaction',
        ];

        /*
         * The PHPUnit process carries test-harness variables (APP_ENV=testing and friends from
         * phpunit.xml) that must not leak into the server: under the testing environment a
         * different configuration loads and the Octane commands are never registered. The server
         * must boot exactly as `php artisan octane:start` from a shell would.
         */
        $environment = getenv();

        unset(
            $environment['APP_ENV'],
            $environment['APP_RUNNING_IN_CONSOLE'],
            $environment['CACHE_DRIVER'],
            $environment['SESSION_DRIVER']
        );

        $environment['WINTER_OCTANE_SMOKE'] = '1';

        $this->serverProcess = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->serverLog, 'a'],
                2 => ['file', $this->serverLog, 'a'],
            ],
            $pipes,
            $this->winterRoot,
            $environment
        );

        if (!is_resource($this->serverProcess)) {
            $this->markTestSkipped('Could not start the octane:start process.');
        }

        /*
         * Generous ceiling: the very first start downloads the FrankenPHP binary.
         */
        $deadline = time() + 180;

        while (time() < $deadline) {
            $status = proc_get_status($this->serverProcess);

            if (!$status['running']) {
                $this->markTestSkipped(
                    "octane:start exited before the server came up (needs network on first run "
                    . "to download FrankenPHP). Log tail:\n" . $this->logTail()
                );
            }

            /*
             * A TCP accept only proves the server process is listening; the worker behind it can
             * still be booting, and a request in that window gets an error response. Readiness is
             * an actual 200 from the health of the diagnostic route.
             */
            [$httpStatus] = $this->fetch('/_octane-smoke/read');

            if ($httpStatus === 200) {
                return;
            }

            usleep(250000);
        }

        $this->markTestSkipped(
            "The server did not accept connections within the timeout. Log tail:\n" . $this->logTail()
        );
    }

    /**
     * Stop the server, politely first, then by port as a backstop for orphaned children.
     */
    protected function stopServer(): void
    {
        if ($this->winterRoot !== '') {
            @exec(sprintf(
                'cd %s && %s artisan octane:stop --server=frankenphp >/dev/null 2>&1',
                escapeshellarg($this->winterRoot),
                escapeshellarg(PHP_BINARY)
            ));
        }

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }

        if ($this->port > 0) {
            @exec(sprintf('lsof -ti :%d 2>/dev/null | xargs kill 2>/dev/null', $this->port));
        }
    }

    /**
     * Fetch a smoke route and decode its JSON.
     *
     * @param string $path
     * @return array
     */
    protected function get(string $path): array
    {
        [$status, $body] = $this->fetch($path);

        $this->assertSame(200, $status, sprintf(
            "GET %s answered %s. Body:\n%s\nServer log tail:\n%s",
            $path,
            $status === null ? 'nothing (connection failed)' : "HTTP $status",
            substr((string) $body, 0, 500),
            $this->logTail()
        ));

        $decoded = json_decode((string) $body, true);

        $this->assertIsArray($decoded, sprintf(
            "GET %s did not return JSON. Body:\n%s",
            $path,
            substr($body, 0, 500)
        ));

        return $decoded;
    }

    /**
     * Move aside a worker file that does not load Winter's bootstrap, so the plugin's command installs
     * its own. tearDown() puts it back.
     *
     * The command keeps any existing worker file except Octane's unmodified stub. A file from an
     * earlier version of the README skips Winter's bootstrap/autoload.php, which lets Laravel's helpers
     * replace Winter's, so the server would not start.
     */
    protected function ensureWorkerStubSuitsWinter(): void
    {
        $worker = $this->winterRoot . '/frankenphp-worker.php';

        if (file_exists($worker) && !str_contains((string) file_get_contents($worker), '/bootstrap/autoload.php')) {
            $this->movedWorker = $worker . '.e2e-backup';
            rename($worker, $this->movedWorker);
        }
    }

    /**
     * Put back the worker file ensureWorkerStubSuitsWinter() moved aside.
     */
    protected function restoreWorker(): void
    {
        if ($this->movedWorker === null) {
            return;
        }

        rename($this->movedWorker, substr($this->movedWorker, 0, -strlen('.e2e-backup')));
        $this->movedWorker = null;
    }

    /**
     * Fetch a path, tolerating error statuses.
     *
     * @param string $path
     * @return array{0: int|null, 1: string|false} HTTP status (null when no response) and body.
     */
    protected function fetch(string $path): array
    {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);

        $status = null;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [$status, $body];
    }

    /**
     * Fetch a path exactly as given. Clients such as curl remove dot segments before sending, which
     * would hide a traversal the server lets through.
     *
     * @param string $path
     * @return array{0: int|null, 1: string} HTTP status (null when no response) and body.
     */
    protected function fetchRaw(string $path): array
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 10);

        if ($socket === false) {
            return [null, ''];
        }

        stream_set_timeout($socket, 10);
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $status = preg_match('#^HTTP/\S+\s(\d{3})#', $head, $matches) ? (int) $matches[1] : null;

        return [$status, $body];
    }

    /**
     * @return int A currently free TCP port.
     */
    protected function findFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * @return string The last portion of the server log, for diagnostics.
     */
    protected function logTail(): string
    {
        if ($this->serverLog === '' || !file_exists($this->serverLog)) {
            return '(no log)';
        }

        return substr((string) file_get_contents($this->serverLog), -2000);
    }
}
