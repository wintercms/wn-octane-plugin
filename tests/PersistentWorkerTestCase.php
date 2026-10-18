<?php

namespace Winter\Octane\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use System\Tests\Bootstrap\PluginTestCase;
use Throwable;

/**
 * Dispatches several requests through one application instance, using Octane's real Worker.
 *
 * Winter's ordinary test cases build a fresh application for every test method, which is the exact
 * opposite of how a persistent application server behaves and means no conventional test can
 * observe state crossing a request boundary. This base class boots Laravel\Octane\Worker once and
 * hands every request to Worker::handle(), so the sandbox cloning, event order, output buffering,
 * error handling and post-request restoration are Octane's own code rather than a reimplementation
 * of it. The one substitution is the application factory: the worker is given the application this
 * test case has already prepared (migrations run, plugin registered) instead of bootstrapping a
 * second one.
 *
 * Responses and errors are collected from the worker's client, which is where a real server would
 * receive them. An exception that escapes the HTTP kernel therefore reaches the tests the same way
 * it reaches production: through Client::error() and the WorkerErrorOccurred event, with
 * RequestTerminated never dispatched.
 *
 * Extends PluginTestCase so the Winter.Octane plugin is registered into the application under
 * test: the request-boundary reset these tests observe is attached by the plugin's register(),
 * exactly as it is in production.
 *
 * @package winter/wn-octane-plugin
 */
abstract class PersistentWorkerTestCase extends PluginTestCase
{
    use ReportsDeprecationsToPhpUnit;

    /**
     * @var \Illuminate\Foundation\Application|null The base application every request sandbox is
     * cloned from, once the worker has booted.
     */
    protected ?Application $workerApplication = null;

    /**
     * @var \Laravel\Octane\Worker|null The real Octane worker driving the dispatches, once booted.
     */
    protected ?\Laravel\Octane\Worker $worker = null;

    /**
     * @var \Laravel\Octane\Contracts\Client|null The recording client handed to the worker.
     */
    protected ?\Laravel\Octane\Contracts\Client $workerClient = null;

    /**
     * @var array<int, \Symfony\Component\HttpFoundation\Response|\Throwable> Responses and exceptions
     * from the most recent dispatchWorkerRequests() call, in order.
     */
    protected array $workerResults = [];

    /**
     * @var array<int, int> How many output buffers each request of the most recent
     * dispatchWorkerRequests() call left open, in order, before they were closed again.
     */
    protected array $workerBufferDepths = [];

    /**
     * Prepares the application the way a worker process starts, and skips when laravel/octane is
     * missing.
     *
     * The plugin's composer.json requires laravel/octane, but the plugin's files can be present
     * in an installation where composer has not run, so these tests skip rather than fail where
     * the package is absent.
     *
     * @return void
     */
    public function setUp(): void
    {
        /*
         * Every Octane server entrypoint requires octane's bin/bootstrap.php, which sets
         * APP_RUNNING_IN_CONSOLE = false before the worker's application exists, so inside a real
         * worker runningInConsole() answers false, during provider registration and boot as much
         * as during requests. Set before parent::setUp() creates the application, so the memo is
         * computed with the production answer rather than patched afterwards, and boot-time
         * branches on runningInConsole() take the path a worker would take.
         */
        $_ENV['APP_RUNNING_IN_CONSOLE'] = false;

        /*
         * A real worker process starts with empty statics. PHPUnit rebuilds the application per
         * test in ONE process, so Halcyon's static cache-manager reference still points at the
         * previous test's application while the next one registers its providers. With console
         * detection now answering false, the register-time flush would reach into that stale
         * manager. Clearing it reproduces the fresh-process starting state.
         */
        \Winter\Storm\Halcyon\Model::setCacheManager(null);

        /*
         * Same reasoning for the reset's own statics: the view-share baseline, the boot-time state
         * and the trait-user memo are per-worker in production, but PHPUnit rebuilds the application
         * per test, and a baseline captured against a previous test's application would be restored
         * into this one.
         */
        foreach ([
            'sharedViewBaseline' => null,
            'bootState' => [],
            'traitUsers' => [],
            'examinedClassCount' => 0,
        ] as $property => $value) {
            (new \ReflectionClass(\Winter\Octane\Classes\ResetsRequestState::class))
                ->getProperty($property)->setValue(null, $value);
        }

        parent::setUp();

        if (!class_exists(\Laravel\Octane\Worker::class)) {
            $this->markTestSkipped('laravel/octane is not installed.');
        }
    }

    /**
     * Restores the console flag and drops the worker, so the next test boots its own.
     *
     * @return void
     */
    public function tearDown(): void
    {
        unset($_ENV['APP_RUNNING_IN_CONSOLE']);

        $this->workerApplication = null;
        $this->worker = null;
        $this->workerClient = null;
        $this->workerResults = [];
        $this->workerBufferDepths = [];

        parent::tearDown();
    }

    /**
     * Run a callback with the plugin manager's table swapped for the given fakes, restoring the
     * original table afterwards even when the callback throws.
     *
     * The table is set by reflection rather than through registerPlugin(), which also wants a
     * path on disk for language and view namespaces. Tests using this are about which plugins the
     * reset selects, so the fakes only have to be present and enabled.
     *
     * @param array<string, \System\Classes\PluginBase> $fakes The fake plugins, keyed by plugin code.
     * @param callable $callback The code to run while the fakes are installed.
     * @param bool $keepExisting Merge the fakes over the real table instead of replacing it.
     * @return mixed The callback's return value.
     */
    protected function withFakePlugins(array $fakes, callable $callback, bool $keepExisting = false): mixed
    {
        $manager    = \System\Classes\PluginManager::instance();
        $plugins    = new \ReflectionProperty($manager, 'plugins');
        $normalized = new \ReflectionProperty($manager, 'normalizedMap');

        $originalPlugins = $plugins->getValue($manager);
        $originalMap     = $normalized->getValue($manager);

        $fakeMap = array_combine(array_keys($fakes), array_keys($fakes));

        $plugins->setValue($manager, $keepExisting ? $originalPlugins + $fakes : $fakes);
        $normalized->setValue($manager, $keepExisting ? $originalMap + $fakeMap : $fakeMap);

        try {
            return $callback();
        }
        finally {
            $plugins->setValue($manager, $originalPlugins);
            $normalized->setValue($manager, $originalMap);
        }
    }

    /**
     * A recording Octane client: responses and errors land here, as they would on a real server.
     *
     * Built here rather than declared at the bottom of this file on purpose. `implements` is resolved
     * when the class is loaded, so a file-scope class naming an Octane contract would make this file
     * unloadable wherever the package is absent, and the skip above would never get the chance to run.
     * An anonymous class is only evaluated when this method is called.
     *
     * @return \Laravel\Octane\Contracts\Client
     */
    protected function makeWorkerClient(): \Laravel\Octane\Contracts\Client
    {
        return new class implements \Laravel\Octane\Contracts\Client
        {
            /**
             * @var array<int, \Laravel\Octane\OctaneResponse> Every response the worker sent, in order.
             */
            public array $responses = [];

            /**
             * @var array<int, \Throwable> Every exception the worker reported, in order.
             */
            public array $errors = [];

            /**
             * Returns the request the test passed in the context, or the current one when it passed none.
             *
             * @param \Laravel\Octane\RequestContext $context
             * @return array{0: \Illuminate\Http\Request, 1: \Laravel\Octane\RequestContext}
             */
            public function marshalRequest(\Laravel\Octane\RequestContext $context): array
            {
                return [$context->data['request'] ?? Request::capture(), $context];
            }

            /**
             * Records a response instead of sending it.
             *
             * @param \Laravel\Octane\RequestContext $context
             * @param \Laravel\Octane\OctaneResponse $response
             * @return void
             */
            public function respond(\Laravel\Octane\RequestContext $context, \Laravel\Octane\OctaneResponse $response): void
            {
                $this->responses[] = $response;
            }

            /**
             * Records an exception that escaped the operation instead of rendering it.
             *
             * @param \Throwable $e
             * @param \Illuminate\Foundation\Application $app
             * @param \Illuminate\Http\Request $request
             * @param \Laravel\Octane\RequestContext $context
             * @return void
             */
            public function error(Throwable $e, Application $app, Request $request, \Laravel\Octane\RequestContext $context): void
            {
                $this->errors[] = $e;
            }
        };
    }

    /**
     * Boot the real Octane worker once, against the application this test case prepared.
     *
     * Worker::boot() normally builds the application through ApplicationFactory, but a second
     * bootstrap would discard the migrations and test state already set up on $this->app. The
     * factory is therefore overridden at exactly one point: createApplication() applies the
     * worker's initial instances (the client binding among them) to the prepared application and
     * warms it, instead of constructing a new one. Everything after that (WorkerStarting,
     * sandboxing, event dispatch, error handling) is Octane's own code.
     *
     * @return \Illuminate\Foundation\Application The base application the worker serves from.
     */
    protected function bootWorker(): Application
    {
        if ($this->workerApplication !== null) {
            return $this->workerApplication;
        }

        $this->workerClient = $this->makeWorkerClient();

        $factory = new class ($this->app) extends \Laravel\Octane\ApplicationFactory
        {
            /**
             * @param \Illuminate\Foundation\Application $prepared The application the test case built.
             */
            public function __construct(protected Application $prepared)
            {
                parent::__construct($prepared->basePath());
            }

            /**
             * Applies the worker's initial instances to the prepared application and warms it.
             *
             * @param array<string, mixed> $initialInstances
             * @return \Illuminate\Foundation\Application
             */
            public function createApplication(array $initialInstances = []): Application
            {
                foreach ($initialInstances as $key => $value) {
                    $this->prepared->instance($key, $value);
                }

                return $this->warm($this->prepared);
            }
        };

        $this->worker = new \Laravel\Octane\Worker($factory, $this->workerClient);
        $this->worker->boot();

        return $this->workerApplication = $this->app;
    }

    /**
     * Dispatch requests through the real worker, one sandbox each.
     *
     * Each result is what the client received for that request: the response, or the throwable
     * Octane routed to Client::error() when the operation failed before responding.
     *
     * @param \Illuminate\Http\Request ...$requests
     * @return array<int, \Symfony\Component\HttpFoundation\Response|\Throwable>
     */
    protected function dispatchWorkerRequests(Request ...$requests): array
    {
        $this->bootWorker();
        $this->workerResults = [];
        $this->workerBufferDepths = [];

        foreach ($requests as $request) {
            $errorsBefore = count($this->workerClient->errors);
            $responsesBefore = count($this->workerClient->responses);

            /*
             * Worker::handle() opens an output buffer and, on the error path, leaves it open; a
             * real server loop absorbs that, PHPUnit reports it as a leaked buffer. Restore the
             * level the test started with.
             */
            $bufferLevel = ob_get_level();

            $this->worker->handle($request, new \Laravel\Octane\RequestContext([]));

            $this->workerBufferDepths[] = ob_get_level() - $bufferLevel;

            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            if (count($this->workerClient->errors) > $errorsBefore) {
                $this->workerResults[] = end($this->workerClient->errors);
            } elseif (count($this->workerClient->responses) > $responsesBefore) {
                $this->workerResults[] = end($this->workerClient->responses)->response;
            } else {
                $this->workerResults[] = new \RuntimeException(
                    'The worker produced neither a response nor an error for ' . $request->path()
                );
            }
        }

        return $this->workerResults;
    }

    /**
     * Dispatch a single request and return its response.
     *
     * @param string $uri
     * @param string $method
     * @param array<string, mixed> $parameters Query or form parameters for the request.
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Throwable The exception the worker reported, when the request failed.
     */
    protected function dispatchWorkerRequest(string $uri, string $method = 'GET', array $parameters = []): Response
    {
        [$result] = $this->dispatchWorkerRequests(Request::create($uri, $method, $parameters));

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /**
     * Dispatch a single request carrying the given cookies, and return the cookies its response sets.
     *
     * The cookies are sent as a browser would send them, so a value taken from an earlier response
     * is still encrypted and the web middleware decrypts it. The returned values are encrypted the
     * same way, ready to be sent back with a later request.
     *
     * @param string $uri
     * @param array<string, string> $cookies Cookie values, keyed by cookie name.
     * @param string $method
     * @return array<string, string> The value of every cookie the response sets, keyed by name.
     * @throws \Throwable The exception the worker reported, when the request failed.
     */
    protected function dispatchWorkerRequestWithCookies(string $uri, array $cookies = [], string $method = 'GET'): array
    {
        [$result] = $this->dispatchWorkerRequests(Request::create($uri, $method, [], $cookies));

        if ($result instanceof Throwable) {
            throw $result;
        }

        $set = [];
        foreach ($result->headers->getCookies() as $cookie) {
            $set[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $set;
    }

    /**
     * Encrypt a cookie value the way the web middleware encrypts the cookies it sends.
     *
     * For a cookie that was queued but never sent, such as one queued by a request that failed before
     * it responded, so a test can send it back as the browser would have.
     *
     * @param string $name
     * @param string $value
     * @return string
     */
    protected function encryptWorkerCookie(string $name, string $value): string
    {
        $encrypter = $this->bootWorker()->make('encrypter');

        return $encrypter->encrypt(
            \Illuminate\Cookie\CookieValuePrefix::create($name, $encrypter->getKey()) . $value,
            false
        );
    }

    /**
     * Register a route on the base application, visible to every sandbox cloned from it.
     *
     * Routes are added ahead of the modules' own routes so a test route is not shadowed by the
     * CMS catch-all, which otherwise resolves unknown paths to a 404 page.
     *
     * @param string $uri
     * @param callable $handler
     * @param string $method
     * @param array<int, string> $middleware Middleware or middleware groups for the route, such as 'web'.
     * @return void
     */
    protected function addWorkerRoute(string $uri, callable $handler, string $method = 'GET', array $middleware = []): void
    {
        $router = $this->bootWorker()->make('router');
        $routes = $router->getRoutes();

        $existing = [];
        foreach ($routes->getRoutes() as $route) {
            $existing[] = $route;
        }

        $newRoute = $router->newRoute([$method], $uri, $handler);

        if ($middleware !== []) {
            $newRoute->middleware($middleware);
        }

        /*
         * RouteCollection has no prepend, so rebuild it with the test route first.
         */
        $rebuilt = new \Illuminate\Routing\RouteCollection();
        $rebuilt->add($newRoute);

        foreach ($existing as $route) {
            $rebuilt->add($route);
        }

        $router->setRoutes($rebuilt);
    }
}
