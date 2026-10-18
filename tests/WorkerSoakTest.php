<?php

namespace Winter\Octane\Tests;

use Backend\Classes\AuthManager;
use Backend\Facades\BackendAuth;
use Backend\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Storm\Parse\Processor\Symfony3Processor;
use Winter\Storm\Parse\Yaml;

/**
 * Serves several hundred mixed requests from one worker and checks that nothing the worker keeps
 * between them grows.
 *
 * The other tests show that one request's state is gone by the next. A leak can also be slow: a
 * listener added on every back-end request, a static array that gains a key per request, a container
 * instance that is added and never replaced. None of those hands one user's data to another on the
 * second request, so a two-request test passes, but each makes the worker use more memory and run
 * more code on every request until it is recycled, and a listener that piles up keeps alive every
 * controller it closed over.
 *
 * Each cycle sends the traffic a site sees: a front-end page, a front-end AJAX handler, the back-end
 * sign-in page, a back-end page for a signed-in administrator, a request that fails after opening a
 * transaction, unguarding models and swapping the YAML parser's processor (the HTTP kernel renders its
 * error page), and a request whose exception escapes the kernel to the worker. After a warm-up, the
 * test samples the worker's long-lived state at the same point of every tenth cycle and requires each
 * count to stay where the warm-up left it, and memory use to stay level. Every request must also
 * start with no open transaction, guarded models and the YAML processor the worker booted with, and
 * a request that was answered must leave no output buffer open.
 *
 * Group "soak": `--group soak` runs it alone, and WINTER_OCTANE_SOAK_CYCLES sets how many cycles it
 * runs.
 */
#[Group('soak')]
class WorkerSoakTest extends PersistentWorkerTestCase
{
    /**
     * Cycles of mixed traffic to serve, six requests each, unless WINTER_OCTANE_SOAK_CYCLES is set.
     */
    protected const CYCLES = 100;

    /**
     * Cycles served before the first sample, so caches that fill once per worker are full.
     */
    protected const WARMUP_CYCLES = 20;

    /**
     * Cycles between samples.
     */
    protected const SAMPLE_EVERY = 10;

    /**
     * How much memory use may rise per request served, in bytes, between the first and second half
     * of the samples.
     *
     * The halves are compared by their medians, after a garbage collection at each sample, so an
     * allocation that is freed again cannot fail the test. One that lasts, such as a class loaded
     * late, can: it fails the test when it is larger than this bound times the requests between the
     * two medians, about 138 KB over the default run. The bound is per request so that it holds for a
     * run of any length. One piled-up back-end listener holds about 13 KB, so a leak of that kind
     * exceeds it many times over, while the run with every known leak fixed rises by under 100 bytes
     * per request.
     */
    protected const MEMORY_GROWTH_PER_REQUEST = 512;

    /**
     * @var array<string, array{count: int, after: string}> State a request found left behind when it
     * started, with how many requests found it and the path of the request before the first of them.
     */
    protected array $violations = [];

    /**
     * Builds the application the way Octane's ApplicationFactory builds a worker's, as
     * BaseContainerDiscoveryTest::createApplication() does, so the base container holds what a
     * production worker's does and the back-end managers live for the whole run.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication(): Application
    {
        if (!class_exists(\Laravel\Octane\ApplicationFactory::class)) {
            return parent::createApplication();
        }

        $app = require __DIR__ . '/../../../../bootstrap/app.php';

        $app->beforeBootstrapping(
            \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
            fn ($app) => $app->setPluginsPath($this->fixturePluginsPath())
        );

        (new \Laravel\Octane\ApplicationFactory($app->basePath()))->bootstrap($app, [
            \Laravel\Octane\Contracts\Client::class => $this->makeWorkerClient(),
        ]);

        $app['cache']->setDefaultDriver('array');
        $app->setLocale('en');
        $app['config']->set('app.key', bin2hex(random_bytes(16)));

        /*
         * As on a production site. The debug error page reads the source of every frame in the
         * trace, which makes each failing request about a hundred times slower to serve.
         */
        $app['config']->set('app.debug', false);

        $app->singleton('backend.auth', function ($app) {
            $app['auth.loaded'] = true;

            return AuthManager::instance();
        });

        if (!file_exists(base_path('config/testing/database.php'))) {
            $app['config']->set('database.connections.testing', [
                'driver'   => 'sqlite',
                'database' => ':memory:',
            ]);
            $app['config']->set('database.default', 'testing');
        }

        return $app;
    }

    /**
     * Clears what the run recorded, and puts models back under guard in case a request left them
     * unguarded, so the next test does not inherit it.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->violations = [];
        Model::reguard();

        parent::tearDown();
    }

    /**
     * Nothing the worker keeps between requests grows while it serves mixed traffic.
     *
     * Only the first and the latest value of each count are kept, and memory as a list of integers,
     * so the measurements themselves add almost nothing to the memory they measure.
     *
     * @return void
     */
    public function testLongLivedWorkerStateStaysFlatUnderMixedTraffic(): void
    {
        $app = $this->bootWorker();
        $requests = $this->prepareTraffic($app);

        $cycles = max(
            static::WARMUP_CYCLES + 4 * static::SAMPLE_EVERY,
            (int) (getenv('WINTER_OCTANE_SOAK_CYCLES') ?: static::CYCLES)
        );

        $depths = array_fill_keys(array_keys($requests), []);
        $first = null;
        $firstCycle = 0;
        $grew = [];
        $latest = [];
        $memory = [];

        for ($cycle = 1; $cycle <= $cycles; $cycle++) {
            foreach ($requests as $kind => $send) {
                [$result, $depth] = $send();

                $depths[$kind][$depth] = ($depths[$kind][$depth] ?? 0) + 1;

                if ($kind === 'escaping-error') {
                    if (!$result instanceof RuntimeException || !str_contains($result->getMessage(), 'escapes the kernel')) {
                        $this->fail("Cycle {$cycle}: the escaping-error request must fail with its own exception, not "
                            . ($result instanceof Response ? 'HTTP ' . $result->getStatusCode() : $result->getMessage()));
                    }
                }
                elseif (!$result instanceof Response) {
                    $this->fail("Cycle {$cycle}: the {$kind} request failed: " . $result->getMessage());
                }
                else {
                    // An answered request, as expected.
                }
            }

            if ($cycle < static::WARMUP_CYCLES || ($cycle - static::WARMUP_CYCLES) % static::SAMPLE_EVERY !== 0) {
                continue;
            }

            $latest = $this->countLongLivedState($app);

            if ($first === null) {
                $first = $latest;
                $firstCycle = $cycle;
            }

            foreach ($latest as $label => $count) {
                if (!isset($grew[$label]) && $count > ($first[$label] ?? 0)) {
                    $grew[$label] = sprintf('%s: %d at cycle %d, %d by cycle %d', $label, $first[$label] ?? 0, $firstCycle, $count, $cycle);
                }
            }

            gc_collect_cycles();
            $memory[] = memory_get_usage();
        }

        $this->assertSame([], $this->describeViolations(), 'Requests started with state an earlier request left behind.');

        $answered = array_diff_key($depths, ['escaping-error' => true]);
        $this->assertSame(
            array_fill_keys(array_keys($answered), [0 => $cycles]),
            $answered,
            'A request that was answered left an output buffer open (depth => number of requests).'
        );
        $this->assertCount(
            1,
            $depths['escaping-error'],
            'Every failed request must leave the same number of output buffers open, not more each time.'
        );

        foreach ($grew as $label => $line) {
            $grew[$label] = $line . sprintf(' (%d at the end)', $latest[$label]);
        }

        $this->assertSame(
            [],
            array_values($grew),
            "This state grew while the worker served the same traffic over and over, so it grows for "
            . "the life of every worker:\n  " . implode("\n  ", $grew)
        );

        $this->assertMemoryStaysFlat($memory, static::SAMPLE_EVERY * count($requests));
    }

    /**
     * Fail when memory use in the second half of the samples is clearly above the first half.
     *
     * @param list<int> $memory Memory use at each sample, in bytes.
     * @param int $requestsPerSample Requests served between two samples.
     * @return void
     */
    protected function assertMemoryStaysFlat(array $memory, int $requestsPerSample): void
    {
        $half = intdiv(count($memory), 2);
        $early = $this->median(array_slice($memory, 0, $half));
        $late = $this->median(array_slice($memory, $half));

        /*
         * The two medians are half the run apart.
         */
        $perRequest = ($late - $early) / (count($memory) / 2 * $requestsPerSample);

        $this->assertLessThan(
            static::MEMORY_GROWTH_PER_REQUEST,
            $perRequest,
            sprintf(
                'Memory use grew by %d bytes per request, from a median of %d bytes in the first half of '
                . "the run to %d bytes in the second, so something is kept for every request. Samples:\n  %s",
                $perRequest,
                $early,
                $late,
                implode(', ', $memory)
            )
        );
    }

    /**
     * Count everything the worker keeps between requests.
     *
     * @param \Illuminate\Foundation\Application $app The base application.
     * @return array<string, int>
     */
    protected function countLongLivedState(Application $app): array
    {
        $events = $app['events'];
        $listeners = (new \ReflectionProperty(\Illuminate\Events\Dispatcher::class, 'listeners'))->getValue($events);
        $wildcards = (new \ReflectionProperty(\Illuminate\Events\Dispatcher::class, 'wildcards'))->getValue($events);

        $counts = ['exception.beforeRender listeners' => 0, 'event listeners' => 0];

        foreach ($listeners as $event => $byPriority) {
            $count = array_sum(array_map('count', $byPriority));
            $counts['event listeners'] += $count;

            if ($event === 'exception.beforeRender') {
                $counts['exception.beforeRender listeners'] = $count;
            }
        }

        $counts['wildcard event listeners'] = array_sum(array_map('count', $wildcards));
        $counts['base container instances'] = count((new \ReflectionProperty(Container::class, 'instances'))->getValue($app));

        foreach ($this->manifestedArrays($app) as $label => $value) {
            $counts[$label] = count($value);
        }

        return $counts;
    }

    /**
     * Describes each kind of state a request found left behind, with how often and after which
     * request it was first found.
     *
     * @return list<string>
     */
    protected function describeViolations(): array
    {
        $lines = [];

        foreach ($this->violations as $problem => $found) {
            $lines[] = sprintf('%s at the start of %d requests, first after /%s', $problem, $found['count'], ltrim($found['after'], '/'));
        }

        return $lines;
    }

    /**
     * Record, at the start of every request and after the reset, any state an earlier request left
     * that the reset or Octane should have cleared.
     *
     * Attached at default priority after the worker booted, so it runs after the reset (attached at
     * the highest priority) and after Octane's own listeners.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function listenForLeftoverState(Application $app): void
    {
        $previous = 'none';
        $processor = new \ReflectionProperty(Yaml::class, 'processor');
        $bootProcessor = $processor->getValue($app->make('parse.yaml'));

        $app['events']->listen(\Laravel\Octane\Events\RequestReceived::class, function ($event) use (&$previous, $processor, $bootProcessor) {
            $sandbox = $event->sandbox;

            $found = [
                'a database transaction was still open' => $sandbox->make('db')->connection()->transactionLevel() !== 0,
                'models were still unguarded' => Model::isUnguarded(),
                'the YAML parser had another processor than the one it booted with'
                    => $processor->getValue($sandbox->make('parse.yaml')) !== $bootProcessor,
            ];

            foreach (array_keys(array_filter($found)) as $problem) {
                $this->violations[$problem] ??= ['count' => 0, 'after' => $previous];
                $this->violations[$problem]['count']++;
            }

            $previous = $event->request->path();
        });
    }

    /**
     * Every array the reset manifests list, and the reset's own static arrays, keyed by
     * Class::$property.
     *
     * @param \Illuminate\Foundation\Application $app The base application.
     * @return array<string, array<mixed>>
     */
    protected function manifestedArrays(Application $app): array
    {
        $arrays = [];
        $reset = new \ReflectionClass(ResetsRequestState::class);

        $statics = $reset->getConstant('STATIC_CACHES');
        $traitUsers = $reset->getProperty('traitUsers')->getValue();

        foreach ($reset->getConstant('TRAIT_STATIC_CACHES') as $trait => $properties) {
            foreach ($traitUsers[$trait] ?? [] as $class) {
                $statics[$class] = $properties;
            }
        }

        foreach ($statics as $class => $properties) {
            if (!class_exists($class, false)) {
                continue;
            }

            foreach (array_keys($properties) as $property) {
                $value = (new \ReflectionProperty($class, $property))->getValue();

                if (is_array($value)) {
                    $arrays["{$class}::\${$property}"] = $value;
                }
            }
        }

        foreach ($reset->getConstant('MANAGER_STATE') as $class => $properties) {
            if (!$app->resolved($class)) {
                continue;
            }

            $instance = $app->make($class);

            foreach (array_keys($properties) as $property) {
                $value = (new \ReflectionProperty($instance, $property))->getValue($instance);

                if (is_array($value)) {
                    $arrays["{$class}->{$property}"] = $value;
                }
            }
        }

        foreach (['warned', 'traitUsers'] as $property) {
            $arrays[ResetsRequestState::class . "::\${$property}"] = $reset->getProperty($property)->getValue();
        }

        return $arrays;
    }

    /**
     * The middle value of a list of integers.
     *
     * @param list<int> $values
     * @return float
     */
    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Register the routes and the request-start probe, sign an administrator in, and return a
     * sender for each kind of request in a cycle.
     *
     * Two browsers send the traffic, an anonymous one and the administrator's, and each sends back
     * the cookies it was sent, as a browser does. A new session for every anonymous request would
     * add a session per request to the test's array session store, which is the test's storage and
     * not the worker's state.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<string, callable(): array{0: \Symfony\Component\HttpFoundation\Response|\Throwable, 1: int}>
     */
    protected function prepareTraffic(Application $app): array
    {
        $admin = User::create([
            'login' => 'soak',
            'email' => 'soak@example.com',
            'password' => 'Soak-Passw0rd',
            'password_confirmation' => 'Soak-Passw0rd',
            'first_name' => 'Soak',
            'last_name' => 'Test',
        ]);
        $admin->is_superuser = true;
        $admin->forceSave();

        $this->addWorkerRoute('_soak/login', function () use ($admin) {
            BackendAuth::login(User::findOrFail($admin->id), false);

            return 'ok';
        }, 'GET', ['web']);

        $this->addWorkerRoute('_soak/fail', function () {
            DB::beginTransaction();
            Model::unguard();

            return app('parse.yaml')->withProcessor(new Symfony3Processor(), function () {
                throw new RuntimeException('soak: failure inside the YAML processor');
            });
        }, 'GET', ['web']);

        \Laravel\Octane\Facades\Octane::route('GET', '/_soak/escape', function () {
            DB::beginTransaction();

            throw new RuntimeException('soak: failure that escapes the kernel');
        });

        $this->listenForLeftoverState($app);

        $anonymous = [];
        $signedIn = [];

        $this->send($signedIn, Request::create('/_soak/login', 'GET'));
        $this->assertNotSame([], $signedIn, 'signing in must send a session cookie');

        $backend = '/' . trim((string) $app['config']->get('cms.backendUri', 'backend'), '/');

        $ajax = function () {
            $request = Request::create('/ajax-test', 'POST');
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('X-WINTER-REQUEST-HANDLER', 'onTest');
            $request->headers->set('X-WINTER-REQUEST-PARTIALS', '');

            return $request;
        };

        /*
         * The browser's cookies are bound by reference: the senders run later, and each must send
         * the cookies the previous response set.
         */
        $sender = function (array &$jar, callable $request, ?int $status = null): callable {
            return function () use (&$jar, $request, $status) {
                return $this->send($jar, $request(), $status);
            };
        };

        return [
            'front-end' => $sender($anonymous, fn () => Request::create('/', 'GET'), 200),
            'ajax' => $sender($anonymous, $ajax, 200),
            'signin' => $sender($anonymous, fn () => Request::create($backend . '/backend/auth/signin', 'GET'), 200),
            'back-end' => $sender($signedIn, fn () => Request::create($backend . '/backend', 'GET'), 200),
            'rendered-error' => $sender($anonymous, fn () => Request::create('/_soak/fail', 'GET'), 500),
            'escaping-error' => $sender($anonymous, fn () => Request::create('/_soak/escape', 'GET')),
        ];
    }

    /**
     * Send a request with a browser's cookies, keep the cookies its response sets, and return the
     * result with the number of output buffers the request left open.
     *
     * @param array<string, string> $jar The browser's cookies, updated from the response.
     * @param \Illuminate\Http\Request $request
     * @param int|null $status The status the response must have, when one is expected.
     * @return array{0: \Symfony\Component\HttpFoundation\Response|\Throwable, 1: int}
     */
    protected function send(array &$jar, Request $request, ?int $status = null): array
    {
        foreach ($jar as $name => $value) {
            $request->cookies->set($name, $value);
        }

        [$result] = $this->dispatchWorkerRequests($request);
        $depth = $this->workerBufferDepths[0];

        /*
         * Two things only a test keeps: the recording client's copy of every response and error, and
         * the collection Laravel fills with every logged exception when it runs unit tests. Left to
         * grow, they would be the largest use of memory the run measures.
         */
        $this->workerClient->responses = [];
        $this->workerClient->errors = [];

        if ($this->workerApplication->bound(\Illuminate\Testing\LoggedExceptionCollection::class)) {
            $this->workerApplication->make(\Illuminate\Testing\LoggedExceptionCollection::class)->splice(0);
        }

        if (!$result instanceof Response) {
            return [$result, $depth];
        }

        foreach ($result->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = (string) $cookie->getValue();
        }

        if ($status !== null && $result->getStatusCode() !== $status) {
            return [new RuntimeException(sprintf(
                '%s answered %d instead of %d',
                $request->getPathInfo(),
                $result->getStatusCode(),
                $status
            )), $depth];
        }

        return [$result, $depth];
    }

    /**
     * A plugins directory holding a single symlink to this plugin, created on first use.
     *
     * Named after this checkout's path, so two checkouts on one machine never share a link that
     * points at the other one's code.
     *
     * @return string
     */
    protected function fixturePluginsPath(): string
    {
        $path = sys_get_temp_dir() . '/winter-octane-boot-order-plugins-' . md5((string) realpath(__DIR__ . '/..'));
        $link = $path . '/winter/octane';

        if (!is_link($link)) {
            @mkdir($path . '/winter', 0755, true);

            /*
             * Another copy of the suite running at the same time may create the link first.
             */
            if (!@symlink(realpath(__DIR__ . '/..'), $link) && !is_link($link)) {
                $this->fail('Could not create the fixture plugin link ' . $link);
            }
        }

        return $path;
    }
}
