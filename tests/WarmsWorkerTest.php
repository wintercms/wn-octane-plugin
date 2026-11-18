<?php

namespace Winter\Octane\Tests;

use Illuminate\Support\Facades\Log;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Classes\WarmsWorker;

/**
 * The reset's first full class scan happens when the worker starts, not on its first request.
 */
class WarmsWorkerTest extends PersistentWorkerTestCase
{
    /**
     * @return void
     */
    public function testTheClassScanRunsBeforeTheFirstRequest(): void
    {
        $count = new \ReflectionProperty(ResetsRequestState::class, 'examinedClassCount');
        $users = new \ReflectionProperty(ResetsRequestState::class, 'traitUsers');
        $savedUsers = $users->getValue();

        $count->setValue(null, 0);
        $users->setValue(null, []);

        try {
            $this->bootWorker();

            $this->assertGreaterThan(0, $count->getValue());
        }
        finally {
            $users->setValue(null, $savedUsers);
            $count->setValue(null, count(get_declared_classes()));
        }
    }

    /**
     * A step that fails is logged and the worker carries on.
     *
     * @return void
     */
    public function testAFailedStepIsLogged(): void
    {
        $this->app->bind('halcyon', fn () => throw new \RuntimeException('no resolver'));

        Log::spy();

        (new WarmsWorker())->handle((object) ['app' => $this->app]);

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * A broken log channel does not turn a failed step into a failed worker boot.
     *
     * @return void
     */
    public function testABrokenLogChannelDoesNotEscape(): void
    {
        $this->app->bind('halcyon', fn () => throw new \RuntimeException('no resolver'));

        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('log channel down'));

        (new WarmsWorker())->handle((object) ['app' => $this->app]);
    }

    /**
     * A worker whose site lists no warm-up paths sends no request when it starts.
     *
     * @return void
     */
    public function testNoWarmUpRequestIsSentByDefault(): void
    {
        $hits = 0;
        $received = 0;

        $this->prependRoute($this->app, '_worker/warm-up', function () use (&$hits) {
            $hits++;

            return 'warm';
        });
        $this->app->make('events')->listen(\Laravel\Octane\Events\RequestReceived::class, function () use (&$received) {
            $received++;
        });

        $this->bootWorker();

        $this->assertSame(0, $hits);
        $this->assertSame(0, $received);
    }

    /**
     * Each listed path is requested once, on the site's own URL, before the worker serves a request,
     * and only that request carries the warm-up attribute.
     *
     * @return void
     */
    public function testWarmUpPathsAreRequestedWhenTheWorkerStarts(): void
    {
        $this->app->make('config')->set('app.url', 'https://warm.example/');
        $this->app->make('config')->set('octane.warm_up_paths', ['/_worker/warm-up', '_worker/warm-up?page=2', null]);

        $seen = [];

        $this->prependRoute($this->app, '_worker/warm-up', function () use (&$seen) {
            $seen[] = [request()->fullUrl(), request()->attributes->get(WarmsWorker::WARM_UP_ATTRIBUTE)];

            return 'warm';
        });

        $bufferLevel = ob_get_level();
        $app = $this->bootWorker();

        $this->assertSame([
            ['https://warm.example/_worker/warm-up', true],
            ['https://warm.example/_worker/warm-up?page=2', true],
        ], $seen);
        $this->assertSame($bufferLevel, ob_get_level());
        $this->assertSame($app, \Illuminate\Container\Container::getInstance());
        $this->assertSame([], $this->workerClient->responses, 'A warm-up response must not reach the server.');
        $this->assertNull(
            $app->make('request')->attributes->get(WarmsWorker::WARM_UP_ATTRIBUTE),
            'The application must not keep the warm-up request as its current request.'
        );
        $this->assertIsString(
            $app->make('log')->getDefaultDriver(),
            'The services Octane hands each request\'s sandbox must still work before the first visitor.'
        );

        $this->dispatchWorkerRequest('/_worker/warm-up');

        $this->assertNull($seen[2][1], 'A visitor\'s request must not carry the warm-up attribute.');
    }

    /**
     * The warm-up requests are sent after the other WorkerStarting listeners have prepared the worker.
     *
     * @return void
     */
    public function testWarmUpRequestsAreSentAfterTheOtherStartListeners(): void
    {
        $this->app->make('config')->set('octane.warm_up_paths', ['/_worker/warm-up']);

        $prepared = false;
        $preparedWhenRequested = null;

        $this->app->make('events')->listen(\Laravel\Octane\Events\WorkerStarting::class, function () use (&$prepared) {
            $prepared = true;
        });
        $this->prependRoute($this->app, '_worker/warm-up', function () use (&$prepared, &$preparedWhenRequested) {
            $preparedWhenRequested = $prepared;

            return 'warm';
        });

        $this->bootWorker();

        $this->assertTrue($preparedWhenRequested);
    }

    /**
     * A warm-up path that answers with an error status is logged, since it warmed nothing.
     *
     * @return void
     */
    public function testAWarmUpRequestAnsweredWithAnErrorStatusIsLogged(): void
    {
        $this->app->make('config')->set('app.url', 'http://localhost');
        $this->app->make('config')->set('octane.warm_up_paths', ['/_worker/gone']);
        $this->prependRoute($this->app, '_worker/gone', fn () => response('gone', 410));

        $logged = [];
        $this->recordWarmUpFailures($logged);

        $this->bootWorker();

        $this->assertSame(
            ['the warm-up request for http://localhost/_worker/gone failed: it was answered with HTTP 410'],
            $logged
        );
    }

    /**
     * A RoadRunner worker starts with its first request waiting, and a Swoole task worker serves no
     * requests, so neither sends a warm-up request.
     *
     * @return void
     */
    public function testWorkersThatGainNothingSendNoWarmUpRequest(): void
    {
        $this->app->make('config')->set('octane.warm_up_paths', ['/_worker/warm-up']);

        $hits = 0;
        $this->prependRoute($this->app, '_worker/warm-up', function () use (&$hits) {
            $hits++;

            return 'warm';
        });

        $client = $this->makeWorkerClient();
        $roadRunner = (new \ReflectionClass(\Laravel\Octane\RoadRunner\RoadRunnerClient::class))->newInstanceWithoutConstructor();

        $this->app->instance(\Laravel\Octane\Contracts\Client::class, $roadRunner);
        (new WarmsWorker())->sendWarmUpRequests((object) ['app' => $this->app]);
        $this->assertSame(0, $hits, 'A RoadRunner worker sent a warm-up request.');

        $this->app->instance(\Laravel\Octane\Contracts\Client::class, $client);
        $this->app->instance('Swoole\Http\Server', (object) ['taskworker' => true]);
        (new WarmsWorker())->sendWarmUpRequests((object) ['app' => $this->app]);
        $this->assertSame(0, $hits, 'A Swoole task worker sent a warm-up request.');

        $this->app->instance('Swoole\Http\Server', (object) ['taskworker' => false]);
        (new WarmsWorker())->sendWarmUpRequests((object) ['app' => $this->app]);
        $this->assertSame(1, $hits, 'A Swoole worker that serves requests sent no warm-up request.');
    }

    /**
     * A router that no longer has the property its current request is kept in is logged and left alone.
     *
     * @return void
     */
    public function testAMissingRouterPropertyIsLoggedAndSkipped(): void
    {
        $router = $this->app->make('router');
        $logged = [];

        $warmsWorker = new class ($logged) extends WarmsWorker
        {
            /**
             * @param array<int, string> $logged
             */
            public function __construct(protected array &$logged)
            {
            }

            /**
             * @param \Illuminate\Routing\Router $router
             * @return void
             */
            public function restoreWith($router, string $property): void
            {
                $this->restoreRouterRequest($router, \Illuminate\Http\Request::create('/boot'), $property);
            }

            /**
             * @param \Throwable $ex
             * @return void
             */
            protected function logQuietly(\Throwable $ex): void
            {
                $this->logged[] = $ex->getMessage();
            }
        };

        $warmsWorker->restoreWith($router, 'renamedRequest');

        $this->assertSame(
            ['the router has no $renamedRequest property, so it keeps the warm-up request until the next request'],
            $logged
        );
        $this->assertFalse(property_exists($router, 'renamedRequest'), 'The restore added a property to the router.');

        $warmsWorker->restoreWith($router, 'currentRequest');

        $this->assertSame('boot', $router->getCurrentRequest()->path());
        $this->assertCount(1, $logged);
    }

    /**
     * A worker that cannot read the warm-up paths logs it and still starts.
     *
     * @return void
     */
    public function testUnreadableWarmUpPathsAreLogged(): void
    {
        Log::spy();

        (new WarmsWorker())->sendWarmUpRequests((object) ['app' => new \Illuminate\Container\Container()]);

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * A warm-up request runs in a sandbox and through the terminate step, as a real request does, and
     * what it leaves in memory, binds or prints does not reach the application or the first visitor.
     *
     * @return void
     */
    public function testAWarmUpRequestLeavesNoStateForTheFirstRequest(): void
    {
        $this->app->make('config')->set('octane.warm_up_paths', ['/_worker/warm-up']);

        $ran = false;
        $terminated = 0;
        $leaked = null;

        $this->prependRoute($this->app, '_worker/warm-up', function () use (&$ran) {
            $ran = true;

            \Illuminate\Support\Facades\View::share('warmUpShare', 'from-the-warm-up');
            app()->instance('warm-up-leftover', true);

            echo 'noise';

            return 'warm';
        });
        $this->prependRoute($this->app, '_worker/share-read', function () use (&$leaked) {
            $leaked = array_key_exists('warmUpShare', app('view')->getShared());

            return 'read';
        });
        $this->app->make('events')->listen(\Laravel\Octane\Events\RequestTerminated::class, function () use (&$terminated) {
            $terminated++;
        });

        $this->expectOutputString('');

        $app = $this->bootWorker();

        $this->assertTrue($ran);
        $this->assertSame(1, $terminated);
        $this->assertFalse($app->bound('warm-up-leftover'), 'The warm-up request ran on the application, not on a sandbox.');

        $this->dispatchWorkerRequest('/_worker/share-read');

        $this->assertFalse($leaked);
    }

    /**
     * A warm-up request that fails outside the HTTP kernel is logged, the worker is not stopped, and it
     * goes on to serve requests.
     *
     * @return void
     */
    public function testAFailedWarmUpRequestDoesNotStopTheWorker(): void
    {
        $this->app->make('config')->set('octane.warm_up_paths', ['/_worker/warm-up', '/_worker/warm-up']);

        $hits = 0;
        $logged = [];

        $this->prependRoute($this->app, '_worker/warm-up', function () use (&$hits) {
            $hits++;

            return 'warm';
        });
        $this->app->make('events')->listen(\Laravel\Octane\Events\RequestHandled::class, function ($event) {
            if ($event->request->attributes->get(WarmsWorker::WARM_UP_ATTRIBUTE)) {
                throw new \RuntimeException('warm-up failed');
            }
        });
        $this->recordWarmUpFailures($logged);

        $bufferLevel = ob_get_level();
        $app = $this->bootWorker();

        $this->assertSame(2, $hits, 'A failed warm-up request must not stop the ones after it.');
        $this->assertSame([
            'the warm-up request for http://localhost/_worker/warm-up failed: warm-up failed',
            'the warm-up request for http://localhost/_worker/warm-up failed: warm-up failed',
        ], $logged);
        $this->assertFalse($this->workerClient->stopped);
        $this->assertSame([], $this->workerClient->errors);
        $this->assertSame($bufferLevel, ob_get_level());
        $this->assertSame($app, \Illuminate\Container\Container::getInstance());

        $this->assertSame('warm', $this->dispatchWorkerRequest('/_worker/warm-up')->getContent());
    }

    /**
     * Have the worker's WarmsWorker record the failures it would log. Mocking the Log facade instead
     * would break the warm-up request itself, because Octane's own listeners use the log manager.
     *
     * @param array<int, string> $logged Receives each failure's message.
     * @return void
     */
    protected function recordWarmUpFailures(array &$logged): void
    {
        $this->app->bind(WarmsWorker::class, function () use (&$logged) {
            return new class ($logged) extends WarmsWorker
            {
                /**
                 * @param array<int, string> $logged
                 */
                public function __construct(protected array &$logged)
                {
                }

                /**
                 * @param \Throwable $ex
                 * @return void
                 */
                protected function logQuietly(\Throwable $ex): void
                {
                    $this->logged[] = $ex->getMessage();
                }
            };
        });
    }

    /**
     * The recording client, made stoppable the way RoadRunner's is, so a test can tell whether Octane
     * stopped the worker.
     *
     * @return \Laravel\Octane\Contracts\Client
     */
    protected function makeWorkerClient(): \Laravel\Octane\Contracts\Client
    {
        return new class implements \Laravel\Octane\Contracts\Client, \Laravel\Octane\Contracts\StoppableClient
        {
            /**
             * @var array<int, \Laravel\Octane\OctaneResponse>
             */
            public array $responses = [];

            /**
             * @var array<int, \Throwable>
             */
            public array $errors = [];

            /**
             * @var bool Whether Octane asked the server to stop this worker.
             */
            public bool $stopped = false;

            /**
             * @param \Laravel\Octane\RequestContext $context
             * @return array{0: \Illuminate\Http\Request, 1: \Laravel\Octane\RequestContext}
             */
            public function marshalRequest(\Laravel\Octane\RequestContext $context): array
            {
                return [\Illuminate\Http\Request::capture(), $context];
            }

            /**
             * @param \Laravel\Octane\RequestContext $context
             * @param \Laravel\Octane\OctaneResponse $response
             * @return void
             */
            public function respond(\Laravel\Octane\RequestContext $context, \Laravel\Octane\OctaneResponse $response): void
            {
                $this->responses[] = $response;
            }

            /**
             * @param \Throwable $e
             * @param \Illuminate\Foundation\Application $app
             * @param \Illuminate\Http\Request $request
             * @param \Laravel\Octane\RequestContext $context
             * @return void
             */
            public function error(\Throwable $e, \Illuminate\Foundation\Application $app, \Illuminate\Http\Request $request, \Laravel\Octane\RequestContext $context): void
            {
                $this->errors[] = $e;
            }

            /**
             * @return void
             */
            public function stop(): void
            {
                $this->stopped = true;
            }
        };
    }
}
