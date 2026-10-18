<?php

namespace Winter\Octane\Tests\E2e;

use PHPUnit\Framework\TestCase;

/**
 * Starts the surrounding Winter installation under a REAL Octane server with one worker, and talks
 * to it over HTTP.
 *
 * Everything outside tests/E2e runs in-process. These tests are the end of the chain: the server,
 * its worker loop and its cookie handling are the production ones, with no test double anywhere in
 * the path. One worker means every request reaches the same worker process, which each test checks
 * through the process ID the diagnostic routes report.
 *
 * The diagnostic routes come from the plugin's routes.php and only exist when WINTER_OCTANE_SMOKE is
 * set, which this class sets for the server process it starts.
 *
 * Opt-in: set WINTER_OCTANE_E2E=1 (CI does). WINTER_OCTANE_E2E_SERVER picks the server: frankenphp
 * (the default), swoole or roadrunner. Swoole needs the swoole extension, and RoadRunner needs the
 * spiral/roadrunner-http and spiral/roadrunner-cli packages and an `rr` binary in the project root or
 * on the PATH. FrankenPHP's binary is downloaded by Octane on first start, so the first run needs the
 * network. A test skips, with the server log attached, when the server cannot come up.
 */
abstract class OctaneServerTestCase extends TestCase
{
    /**
     * The servers these tests can start.
     */
    protected const SERVERS = ['frankenphp', 'swoole', 'roadrunner'];

    /**
     * @var resource|null The octane:start process.
     */
    protected $serverProcess = null;

    /**
     * @var string The server under test.
     */
    protected string $server = '';

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

    /**
     * Skips unless the test is opted in and a configured Winter installation surrounds the plugin.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if (getenv('WINTER_OCTANE_E2E') !== '1') {
            $this->markTestSkipped('End-to-end test is opt-in; set WINTER_OCTANE_E2E=1 to run it.');
        }

        $server = getenv('WINTER_OCTANE_E2E_SERVER') ?: 'frankenphp';

        if (!in_array($server, static::SERVERS, true)) {
            $this->fail('WINTER_OCTANE_E2E_SERVER must be one of ' . implode(', ', static::SERVERS) . ", not {$server}.");
        }

        $this->server = $server;

        $winterRoot = realpath(__DIR__ . '/../../../../..');

        if ($winterRoot === false || !file_exists($winterRoot . '/.env')) {
            $this->markTestSkipped('No configured Winter installation (.env) surrounds the plugin.');
        }

        $this->winterRoot = $winterRoot;
    }

    /**
     * Stops the server and puts back any worker file the test moved aside.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->stopServer();
        $this->restoreWorker();

        parent::tearDown();
    }

    /**
     * Start octane:start with a single worker and wait until the worker answers.
     *
     * @return void
     */
    protected function startServer(): void
    {
        if ($this->server === 'frankenphp') {
            $this->ensureWorkerStubSuitsWinter();
        }

        $this->port = $this->findFreePort();
        $this->serverLog = (string) tempnam(sys_get_temp_dir(), 'octane-e2e-');

        $command = [
            PHP_BINARY,
            'artisan',
            'octane:start',
            '--server=' . $this->server,
            '--host=127.0.0.1',
            '--port=' . $this->port,
            '--workers=1',
            '--no-interaction',
        ];

        if ($this->server === 'swoole') {
            $command[] = '--task-workers=1';
        }
        elseif ($this->server === 'roadrunner') {
            $command[] = '--rpc-port=' . $this->findFreePort();
        }
        else {
            // FrankenPHP takes no extra options.
        }

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
         * Generous ceiling: the very first FrankenPHP start downloads the binary.
         */
        $deadline = time() + 180;

        while (time() < $deadline) {
            $status = proc_get_status($this->serverProcess);

            if (!$status['running']) {
                $this->markTestSkipped(
                    "octane:start --server={$this->server} exited before the server came up (FrankenPHP "
                    . "needs the network on its first run). Log tail:\n" . $this->logTail()
                );
            }

            /*
             * A TCP accept only proves the server process is listening; the worker behind it can
             * still be booting, and a request in that window gets an error response. Readiness is
             * an actual 200 from the diagnostic route.
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
     *
     * @return void
     */
    protected function stopServer(): void
    {
        if ($this->serverProcess !== null) {
            $this->artisan(['octane:stop', '--server=' . $this->server]);
        }

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }

        if ($this->port > 0) {
            @exec(sprintf('lsof -ti :%d 2>/dev/null | xargs kill 2>/dev/null', $this->port));
        }

        if ($this->serverLog !== '') {
            @unlink($this->serverLog);
            $this->serverLog = '';
        }
    }

    /**
     * Fetch a diagnostic route and decode its JSON.
     *
     * @param string $path
     * @return array<string, mixed>
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
            substr((string) $body, 0, 500)
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
     *
     * @return void
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
     *
     * @return void
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
     * @param string $method
     * @param list<string> $headers Extra request headers, such as "Range: bytes=0-9".
     * @return array{0: int|null, 1: string, 2: string} HTTP status (null when no response), body,
     * and the response's header block.
     */
    protected function fetchRaw(string $path, string $method = 'GET', array $headers = []): array
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 10);

        if ($socket === false) {
            return [null, '', ''];
        }

        $extra = '';
        foreach ($headers as $header) {
            $extra .= $header . "\r\n";
        }

        stream_set_timeout($socket, 10);
        fwrite($socket, "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\n{$extra}Connection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $status = preg_match('#^HTTP/\S+\s(\d{3})#', $head, $matches) ? (int) $matches[1] : null;

        if (preg_match('#^Transfer-Encoding:\s*chunked#mi', $head)) {
            $body = $this->decodeChunked($body);
        }

        return [$status, $body, $head];
    }

    /**
     * Join the chunks of a chunked response body.
     *
     * @param string $body
     * @return string
     */
    protected function decodeChunked(string $body): string
    {
        $decoded = '';

        while (preg_match('#^([0-9a-fA-F]+)[^\r\n]*\r\n#', $body, $matches)) {
            $length = (int) hexdec($matches[1]);

            if ($length === 0) {
                break;
            }

            $decoded .= substr($body, strlen($matches[0]), $length);
            $body = substr($body, strlen($matches[0]) + $length + 2);
        }

        return $decoded;
    }

    /**
     * Send a request as a browser with the given cookie jar would, without following redirects, and
     * update the jar from the response's Set-Cookie headers.
     *
     * @param string $method
     * @param string $path
     * @param array<string, string> $jar The browser's cookies, keyed by name.
     * @param array<string, string> $form Form fields to post.
     * @return array{status: int|null, headers: list<string>, body: string, set: array<string, string>}
     * The status (null when no response), the raw response headers, the body, and the cookies the
     * response set.
     */
    protected function browse(string $method, string $path, array &$jar, array $form = []): array
    {
        $headers = [];

        if ($jar !== []) {
            $pairs = [];
            foreach ($jar as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $content = '';

        if ($form !== []) {
            $content = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $body = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];

        $status = null;
        $set = [];

        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
            elseif (preg_match('#^Set-Cookie:\s*([^=;\s]+)=([^;]*)(.*)$#i', $header, $matches)) {
                $set[$matches[1]] = $matches[2];

                /*
                 * A cookie set to an empty value or with an expiry in the past is the server deleting
                 * it, as a browser would.
                 */
                if ($matches[2] === '' || $matches[2] === 'deleted' || preg_match('#max-age=0\b#i', $matches[3])) {
                    unset($jar[$matches[1]]);
                }
                else {
                    $jar[$matches[1]] = $matches[2];
                }
            }
            else {
                // Any other header is not needed here.
            }
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $body, 'set' => $set];
    }

    /**
     * Run an artisan command in the Winter installation, as the server's own environment would.
     *
     * @param list<string> $arguments
     * @return string The command's combined output.
     */
    protected function artisan(array $arguments): string
    {
        $environment = getenv();

        unset(
            $environment['APP_ENV'],
            $environment['APP_RUNNING_IN_CONSOLE'],
            $environment['CACHE_DRIVER'],
            $environment['SESSION_DRIVER']
        );

        $process = proc_open(
            array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-interaction']),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->winterRoot,
            $environment
        );

        $this->assertIsResource($process, 'could not run artisan ' . implode(' ', $arguments));

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return $output;
    }

    /**
     * Asks the operating system for a port no other process is listening on.
     *
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
     * Reads the end of the server log, to attach to a failure or skip message.
     *
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
