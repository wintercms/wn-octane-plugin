<?php

namespace Winter\Octane\Tests;

use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use RuntimeException;
use System\Twig\Loader as TwigLoader;
use Backend\Classes\AuthManager;
use Winter\Storm\Exception\ErrorHandler;

/**
 * Stands in for a plugin's auth manager, such as Winter.User's front-end one, that this plugin cannot
 * list by name.
 */
class FrontEndAuthManager extends \Winter\Storm\Auth\Manager
{
}

/**
 * A controller for a route whose request fails after the kernel has answered it.
 */
class FailedRequestController extends \Illuminate\Routing\Controller
{
    /**
     * @return string
     */
    public function index(): string
    {
        return 'ok';
    }
}

/**
 * State produced by one operation must not be visible to the next.
 *
 * These run through Octane's real ApplicationGateway against a single application instance, so a
 * leak here is the same leak a worker would exhibit in production.
 */
class RequestStateResetTest extends PersistentWorkerTestCase
{
    /**
     * One booted worker serves several requests in a row, each with a normal response.
     *
     * @return void
     */
    public function testTheWorkerServesSeveralRequestsFromOneApplication(): void
    {
        $this->addWorkerRoute('_worker/ok', fn () => 'ok');

        $first = $this->dispatchWorkerRequest('/_worker/ok');
        $second = $this->dispatchWorkerRequest('/_worker/ok');

        $this->assertEquals(200, $first->getStatusCode());
        $this->assertEquals(200, $second->getStatusCode());
        $this->assertEquals('ok', $first->getContent());
        $this->assertEquals('ok', $second->getContent());
    }

    /**
     * Each operation gets its own sandbox, and the base application is restored afterwards.
     *
     * @return void
     */
    public function testEachOperationRunsInItsOwnSandbox(): void
    {
        $seen = [];

        $this->addWorkerRoute('_worker/sandbox', function () use (&$seen) {
            $seen[] = spl_object_id(\Illuminate\Container\Container::getInstance());

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/sandbox', 'GET'),
            Request::create('/_worker/sandbox', 'GET')
        );

        $this->assertCount(2, $seen);
        $this->assertNotEquals($seen[0], $seen[1], 'each operation must run in its own sandbox');
        $this->assertNotContains(
            spl_object_id($this->workerApplication),
            $seen,
            'operations must not run against the base application'
        );
    }

    /**
     * The execution context is derived from the request path and cached as a singleton, so without
     * a reset the first request a worker serves decides it for every later request.
     *
     * @return void
     */
    public function testExecutionContextIsRederivedForEachRequest(): void
    {
        $contexts = [];

        $backendUri = trim((string) $this->app['config']->get('cms.backendUri', 'backend'), '/');

        $this->addWorkerRoute('_worker/context', function () use (&$contexts) {
            $contexts[] = app('execution.context');

            return 'ok';
        });
        $this->addWorkerRoute($backendUri . '/_worker/context', function () use (&$contexts) {
            $contexts[] = app('execution.context');

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/' . $backendUri . '/_worker/context', 'GET'),
            Request::create('/_worker/context', 'GET'),
            Request::create('/' . $backendUri . '/_worker/context', 'GET')
        );

        $this->assertSame(
            ['back-end', 'front-end', 'back-end'],
            $contexts,
            'the context must follow each request rather than the first one served'
        );
    }

    /**
     * The reset must run no matter what other listeners do.
     *
     * The dispatcher stops propagation when a listener returns false, but Octane's
     * ApplicationGateway ignores that return value and proceeds into the kernel anyway, so a
     * reset listener that ran later in the chain could be silently skipped for an operation that
     * is still served. The reset is therefore attached at the highest priority, ahead of anything
     * attached at a normal one.
     *
     * @return void
     */
    public function testTheResetCannotBeSkippedByAHaltingListener(): void
    {
        $app = $this->bootWorker();

        $this->addWorkerRoute('_worker/unskippable', fn () => 'ok');

        /*
         * Priority 1000 sorts ahead of every default-priority listener (Octane's included), so if
         * the reset were attached at default priority, this halts the chain before it.
         */
        $app['events']->listen(
            \Laravel\Octane\Events\RequestReceived::class,
            fn () => false,
            1000
        );

        $probe = new class ($app) extends \System\Classes\PluginBase
        {
            /**
             * @var int Number of times the reset reached this plugin.
             */
            public int $resets = 0;

            /**
             * Counts each time the reset reaches this plugin.
             *
             * @return void
             */
            public function resetWorkerState(): void
            {
                $this->resets++;
            }
        };

        $this->withFakePlugins(
            ['Winter.Tests.SkipProbe' => $probe],
            fn () => $this->dispatchWorkerRequests(Request::create('/_worker/unskippable', 'GET')),
            keepExisting: true
        );

        $this->assertSame(
            1,
            $probe->resets,
            'A listener returning false must not be able to skip the request-boundary reset.'
        );
    }

    /**
     * Octane's own listeners must observe already-cleaned state, and the per-operation objects
     * they inject must survive the reset.
     *
     * The second half is the regression that matters: the reset restores the view factory's
     * boot-time shared data, and Octane's GiveNewApplicationInstanceToViewFactory injects the
     * CURRENT sandbox into shared['app'] on every operation. If the reset ran after Octane's
     * listeners, its baseline would capture the first operation's sandbox (a dead object once
     * that operation's sandbox is flushed) and replay it into every later render.
     *
     * @return void
     */
    public function testViewSharesResetToBootStateAndOctaneReinjectsLiveObjects(): void
    {
        $this->bootWorker();

        $observed = [];

        $this->addWorkerRoute('_worker/share-set', function () {
            \Illuminate\Support\Facades\View::share('leakedShare', 'from-request-one');

            return 'shared';
        });

        $this->addWorkerRoute('_worker/share-read', function () use (&$observed) {
            $shared = app('view')->getShared();

            $observed['leakPresent'] = array_key_exists('leakedShare', $shared);
            $observed['appIsCurrentSandbox'] =
                ($shared['app'] ?? null) === \Illuminate\Container\Container::getInstance();

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/share-set', 'GET'),
            Request::create('/_worker/share-read', 'GET')
        );

        $this->assertFalse(
            $observed['leakPresent'],
            'view data shared during one request must not be visible to the next'
        );
        $this->assertTrue(
            $observed['appIsCurrentSandbox'],
            'shared["app"] must be the CURRENT operation\'s sandbox — a stale instance here means '
            . 'the reset ran after Octane\'s listeners and snapshotted a dead application object'
        );
    }

    /**
     * A request that OVERWRITES a boot-time view share must not hand its value to later requests.
     *
     * Dropping keys added since boot is not enough. A multi-site plugin overwriting a boot-time
     * key per request (a site-specific app name, say) leaves the key present, so a keys-only
     * cleanup keeps the previous request's VALUE. The reset restores the boot-time value itself.
     *
     * @return void
     */
    public function testOverwritingABootTimeShareDoesNotLeakIntoTheNextRequest(): void
    {
        $app = $this->bootWorker();

        /*
         * Shared before the first operation, so the worker's baseline records it as boot state.
         */
        $app['view']->share('octaneBaselineShare', 'boot-value');

        $seen = [];

        $this->addWorkerRoute('_worker/overwrite-set', function () {
            \Illuminate\Support\Facades\View::share('octaneBaselineShare', 'request-one-value');

            return 'overwritten';
        });

        $this->addWorkerRoute('_worker/overwrite-read', function () use (&$seen) {
            $seen[] = app('view')->getShared()['octaneBaselineShare'] ?? null;

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/overwrite-set', 'GET'),
            Request::create('/_worker/overwrite-read', 'GET')
        );

        $this->assertSame(
            ['boot-value'],
            $seen,
            'A boot-time share overwritten during one request must return to its boot-time value '
            . 'for the next.'
        );
    }

    /**
     * The auth manager is resolved into the base container at boot, so the sandbox never discards
     * it and a resolved user would otherwise be visible to the next request.
     *
     * @return void
     */
    public function testAuthenticationStateDoesNotCrossRequests(): void
    {
        $seen = [];

        $this->addWorkerRoute('_worker/auth-set', function () {
            $manager = AuthManager::instance();
            $reflection = new \ReflectionObject($manager);
            $property = $reflection->getProperty('user');
            $property->setValue($manager, new \stdClass());

            return 'set';
        });

        $this->addWorkerRoute('_worker/auth-read', function () use (&$seen) {
            $manager = AuthManager::instance();
            $reflection = new \ReflectionObject($manager);
            $property = $reflection->getProperty('user');
            $seen[] = $property->getValue($manager);

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/auth-set', 'GET'),
            Request::create('/_worker/auth-read', 'GET')
        );

        $this->assertCount(1, $seen);
        $this->assertNull($seen[0], 'a user resolved in one request must not be visible in the next');
    }

    /**
     * The reset clears the address on every auth manager, so the middleware must record it again on
     * every one, or a front-end manager throttles all clients as one address.
     *
     * @return void
     */
    public function testEveryStormAuthManagerRecordsEachRequestsClientAddress(): void
    {
        $this->bootWorker();

        // Resolve the manager before the requests, as a plugin would, so it outlives each sandbox.
        FrontEndAuthManager::instance();
        $seen = [];

        $this->addWorkerRoute('_worker/front-auth-ip', function () use (&$seen) {
            $seen[] = FrontEndAuthManager::instance()->ipAddress;

            return 'ip';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/front-auth-ip', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']),
            Request::create('/_worker/front-auth-ip', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.2'])
        );

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $seen);
    }

    /**
     * The auth manager captures the client address once, at construction. Each HTTP request must
     * record its own, or login throttling is scoped to the first request's address.
     *
     * @return void
     */
    public function testTheAuthManagerRecordsEachRequestsClientAddress(): void
    {
        $seen = [];

        AuthManager::instance();

        $this->addWorkerRoute('_worker/auth-ip', function () use (&$seen) {
            $seen[] = AuthManager::instance()->ipAddress;

            return 'ip';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/auth-ip', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']),
            Request::create('/_worker/auth-ip', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.2'])
        );

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $seen);
    }

    /**
     * Storm caches duplicate queries in memory, and only a write made through this process clears
     * that cache. A row another worker changes must still be read fresh by the next request here,
     * or a revoked permission or a changed setting is never seen.
     *
     * @return void
     */
    public function testQueryMemoryCacheDoesNotCrossRequests(): void
    {
        $group = \Backend\Models\UserGroup::create(['name' => 'Before', 'code' => 'octane-query-cache']);
        $seen = [];

        $this->addWorkerRoute('_worker/query-cache-read', function () use (&$seen) {
            $seen[] = \Backend\Models\UserGroup::where('code', 'octane-query-cache')->first()->name;

            return 'read';
        });

        $this->dispatchWorkerRequests(Request::create('/_worker/query-cache-read', 'GET'));

        /*
         * Another worker's write: it reaches the database without passing through this process's
         * query builder, so nothing here flushes the in-memory cache.
         */
        $group->getConnection()->getPdo()->exec(
            "update backend_user_groups set name = 'After' where id = " . (int) $group->id
        );

        $this->dispatchWorkerRequests(Request::create('/_worker/query-cache-read', 'GET'));

        $this->assertSame(['Before', 'After'], $seen);
    }

    /**
     * Storm's getUser() returns the user a manager already holds without checking the session, so
     * every auth manager that outlives an operation must be cleared, not only the back-end one.
     *
     * @return void
     */
    public function testEveryStormAuthManagerForgetsItsUser(): void
    {
        $this->bootWorker();
        FrontEndAuthManager::instance();
        $seen = [];

        $this->addWorkerRoute('_worker/front-auth-set', function () {
            (new \ReflectionProperty(\Winter\Storm\Auth\Manager::class, 'user'))
                ->setValue(FrontEndAuthManager::instance(), new \stdClass());

            return 'set';
        });

        $this->addWorkerRoute('_worker/front-auth-read', function () use (&$seen) {
            $seen[] = (new \ReflectionProperty(\Winter\Storm\Auth\Manager::class, 'user'))
                ->getValue(FrontEndAuthManager::instance());

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/front-auth-set', 'GET'),
            Request::create('/_worker/front-auth-read', 'GET')
        );

        $this->assertSame([null], $seen, 'a front-end user resolved in one request must not be visible in the next');
    }

    /**
     * An abandoned transaction is rolled back at the start of the NEXT operation. Its rollback
     * callbacks belong to the request that registered them and must not run as the next one.
     *
     * @return void
     */
    public function testRollbackCallbacksOfAnAbandonedTransactionDoNotRun(): void
    {
        $ran = [];

        $this->addWorkerRoute('_worker/tx-rollback-callback', function () use (&$ran) {
            $connection = app('db')->connection();
            $connection->beginTransaction();
            $connection->afterRollBack(function () use (&$ran) {
                $ran[] = 'rollback callback';
            });

            return 'staged';
        });

        $this->addWorkerRoute('_worker/tx-next', fn () => 'next');

        $this->dispatchWorkerRequests(
            Request::create('/_worker/tx-rollback-callback', 'GET'),
            Request::create('/_worker/tx-next', 'GET')
        );

        $this->assertSame([], $ran);
    }

    /**
     * Component properties are memoized in a static for the request. A worker must read them again,
     * or an edit served by another worker is never seen here.
     *
     * @return void
     */
    public function testComponentPropertyMapDoesNotCrossRequests(): void
    {
        $seen = [];

        $this->addWorkerRoute('_worker/component-map-set', function () {
            (new \ReflectionProperty(\Cms\Classes\CmsCompoundObject::class, 'objectComponentPropertyMap'))
                ->setValue(null, ['stale' => true]);

            return 'set';
        });

        $this->addWorkerRoute('_worker/component-map-read', function () use (&$seen) {
            $seen[] = (new \ReflectionProperty(\Cms\Classes\CmsCompoundObject::class, 'objectComponentPropertyMap'))
                ->getValue();

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/component-map-set', 'GET'),
            Request::create('/_worker/component-map-read', 'GET')
        );

        $this->assertSame([null], $seen);
    }

    /**
     * Halcyon's in-memory record cache is held statically, so records cached by one request would
     * otherwise be served to the next. Uses the file store because Octane already flushes the array
     * store between operations.
     *
     * @return void
     */
    public function testHalcyonRequestCacheDoesNotCrossRequests(): void
    {
        $seen = [];

        $this->addWorkerRoute('_worker/halcyon-set', function () {
            $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
            $store->putInMemoryCache('worker-test-key', 'cached');

            return 'set';
        });

        $this->addWorkerRoute('_worker/halcyon-read', function () use (&$seen) {
            $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
            $seen[] = (new \ReflectionProperty($store, 'cache'))->getValue($store);

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/halcyon-set', 'GET'),
            Request::create('/_worker/halcyon-read', 'GET')
        );

        $this->assertCount(1, $seen);
        $this->assertArrayNotHasKey('worker-test-key', $seen[0]);
    }

    /**
     * A plugin can give Halcyon\Model its own cache manager. The application's cache manager is
     * still a MemoryCacheManager held for the life of the worker, so its in-memory values must not
     * reach the next request either.
     */
    public function testApplicationCacheMemoryDoesNotCrossRequestsWhenHalcyonUsesAnotherManager()
    {
        $seen = [];

        $this->addWorkerRoute('_worker/app-cache-set', function () {
            \Winter\Storm\Halcyon\Model::setCacheManager(new \Winter\Storm\Halcyon\MemoryCacheManager(app()));
            $store = app('cache')->store('file');
            $store->putInMemoryCache('worker-test-key', 'cached');

            return 'set';
        });

        $this->addWorkerRoute('_worker/app-cache-read', function () use (&$seen) {
            $store = app('cache')->store('file');
            $seen[] = (new \ReflectionProperty($store, 'cache'))->getValue($store);

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/app-cache-set', 'GET'),
            Request::create('/_worker/app-cache-read', 'GET')
        );

        $this->assertCount(1, $seen);
        $this->assertArrayNotHasKey('worker-test-key', $seen[0]);
    }

    /**
     * An unbalanced mask left by a throwing request would otherwise be applied to the next
     * request's exception.
     *
     * @return void
     */
    public function testErrorHandlerMasksDoNotCrossRequests(): void
    {
        $activeMasks = [];

        $this->addWorkerRoute('_worker/mask-apply', function () {
            ErrorHandler::applyMask(new RuntimeException('mask from a previous request'));

            return 'applied';
        });

        $this->addWorkerRoute('_worker/mask-read', function () use (&$activeMasks) {
            $property = (new \ReflectionClass(ErrorHandler::class))->getProperty('activeMask');
            $activeMasks[] = $property->getValue();

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/mask-apply', 'GET'),
            Request::create('/_worker/mask-read', 'GET')
        );

        $this->assertCount(1, $activeMasks);
        $this->assertNull($activeMasks[0], 'an applied mask must not survive the request boundary');
    }

    /**
     * The Twig loader's include gate, opened by one request, is closed again when the next request
     * starts.
     *
     * @return void
     */
    public function testTwigIncludeGateIsClosedAtTheStartOfEachRequest(): void
    {
        $gates = [];

        $this->addWorkerRoute('_worker/gate-open', function () {
            TwigLoader::$allowInclude = true;

            return 'opened';
        });

        $this->addWorkerRoute('_worker/gate-read', function () use (&$gates) {
            $gates[] = TwigLoader::$allowInclude;

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/gate-open', 'GET'),
            Request::create('/_worker/gate-read', 'GET')
        );

        $this->assertSame([false], $gates, 'the include gate must be closed for every new request');
    }

    /**
     * No stock Octane listener unwinds an abandoned transaction, so an open one would hold its
     * snapshot open for the next operation.
     *
     * @return void
     */
    public function testAbandonedTransactionsAreRolledBack(): void
    {
        $levels = [];

        $this->addWorkerRoute('_worker/tx-open', function () {
            app('db')->connection()->beginTransaction();

            return 'opened';
        });

        $this->addWorkerRoute('_worker/tx-read', function () use (&$levels) {
            $levels[] = app('db')->connection()->transactionLevel();

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/tx-open', 'GET'),
            Request::create('/_worker/tx-read', 'GET')
        );

        $this->assertSame([0], $levels, 'an abandoned transaction must be rolled back to depth zero');
    }

    /**
     * After-commit callbacks staged in a transaction that one request abandoned are gone by the next
     * request.
     *
     * @return void
     */
    public function testStagedTransactionCallbacksDoNotCrossRequests(): void
    {
        $pending = [];

        $this->addWorkerRoute('_worker/tx-callback', function () {
            $connection = app('db')->connection();
            $connection->beginTransaction();
            $connection->afterCommit(fn () => null);

            return 'staged';
        });

        $this->addWorkerRoute('_worker/tx-callback-read', function () use (&$pending) {
            $pending[] = app('db.transactions')->getPendingTransactions()->count();

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/tx-callback', 'GET'),
            Request::create('/_worker/tx-callback-read', 'GET')
        );

        $this->assertSame([0], $pending, 'staged transaction callbacks must not outlive their operation');
    }

    /**
     * Octane reports a failed operation's exception from a default-priority WorkerErrorOccurred
     * listener. The reset on that event must run after it, so the report still sees the request's
     * user, and must still clear the user afterwards.
     *
     * @return void
     */
    public function testAFailedOperationIsReportedBeforeItsStateIsCleared(): void
    {
        $this->bootWorker();
        $userWhenReported = 'not reported';

        $this->app['events']->listen(WorkerErrorOccurred::class, function () use (&$userWhenReported) {
            $userWhenReported = (new \ReflectionProperty(\Winter\Storm\Auth\Manager::class, 'user'))
                ->getValue(AuthManager::instance());
        });

        /*
         * An Octane route rather than addWorkerRoute(): Octane serves it outside the HTTP kernel, so
         * the exception escapes to the worker instead of being rendered as an error page.
         */
        \Laravel\Octane\Facades\Octane::route('GET', '/_worker/fail-signed-in', function () {
            (new \ReflectionProperty(\Winter\Storm\Auth\Manager::class, 'user'))
                ->setValue(AuthManager::instance(), new \stdClass());

            throw new RuntimeException('failure while signed in');
        });

        $results = $this->dispatchWorkerRequests(Request::create('/_worker/fail-signed-in', 'GET'));

        $this->assertInstanceOf(RuntimeException::class, $results[0]);
        $this->assertInstanceOf(\stdClass::class, $userWhenReported, 'the report must see the failed request\'s user');
        $this->assertNull(
            (new \ReflectionProperty(\Winter\Storm\Auth\Manager::class, 'user'))->getValue(AuthManager::instance()),
            'the error-path reset must still clear the user'
        );
    }

    /**
     * An exception that escapes the HTTP kernel skips ApplicationGateway::terminate(), so
     * RequestTerminated is never dispatched and cleanup attached only to that event would not run.
     * The reset must still clear what the failed operation left behind.
     *
     * @return void
     */
    public function testAnEscapingExceptionSkipsRequestTerminatedButStillCleansUp(): void
    {
        $events = [];

        $this->app['events']->listen(RequestTerminated::class, function () use (&$events) {
            $events[] = 'RequestTerminated';
        });
        $this->app['events']->listen(WorkerErrorOccurred::class, function () use (&$events) {
            $events[] = 'WorkerErrorOccurred';
        });

        $gates = [];

        \Laravel\Octane\Facades\Octane::route('GET', '/_worker/escape', function () {
            TwigLoader::$allowInclude = true;

            throw new RuntimeException('escaping failure');
        });

        $this->addWorkerRoute('_worker/after-escape', function () use (&$gates) {
            $gates[] = TwigLoader::$allowInclude;

            return 'read';
        });

        /*
         * Dispatched separately so the event assertions cover only the failing operation. The
         * follow-up request succeeds and legitimately dispatches RequestTerminated.
         */
        $results = $this->dispatchWorkerRequests(Request::create('/_worker/escape', 'GET'));

        $this->assertInstanceOf(RuntimeException::class, $results[0]);
        $this->assertContains('WorkerErrorOccurred', $events);
        $this->assertNotContains(
            'RequestTerminated',
            $events,
            'an escaping exception must skip terminate(), which is why cleanup runs on the way in'
        );

        $this->dispatchWorkerRequests(Request::create('/_worker/after-escape', 'GET'));
        $this->assertSame(
            [false],
            $gates,
            'state left behind by a failed operation must still be cleared for the next one'
        );
    }

    /**
     * Code that holds the base container, such as the CMS error handler, sees each request's execution
     * context rather than the first one that asked through the base container.
     *
     * Octane gives the base container each request too, so an execution context resolved through it is
     * derived from that request and then cached on the base container, which every later sandbox is
     * cloned from.
     *
     * @return void
     */
    public function testTheBaseContainerRederivesTheExecutionContextForEachRequest(): void
    {
        $base = $this->bootWorker();
        $seen = [];

        $backendUri = trim((string) $this->app['config']->get('cms.backendUri', 'backend'), '/');

        $this->addWorkerRoute('_worker/base-context', function () use ($base, &$seen) {
            $seen[] = $base->runningInBackend();

            return 'ok';
        });
        $this->addWorkerRoute($backendUri . '/_worker/base-context', function () use ($base, &$seen) {
            $seen[] = $base->runningInBackend();

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/' . $backendUri . '/_worker/base-context', 'GET'),
            Request::create('/_worker/base-context', 'GET')
        );

        $this->assertSame(
            [true, false],
            $seen,
            'the base container must not keep the back-end context of the request before'
        );
    }

    /**
     * A request that fails after the kernel answered it does not leave its controller on its route.
     *
     * Octane drops the route's controller in ApplicationGateway::terminate(), which a failure that
     * escapes to the worker skips, so the controller instance would stay attached to the route for
     * the rest of the worker's life. The reset on WorkerErrorOccurred drops it straight away, and the
     * next request's reset would drop it if that one did not.
     *
     * @return void
     */
    public function testAFailedRequestDoesNotLeaveItsControllerOnItsRoute(): void
    {
        $app = $this->bootWorker();

        $this->addWorkerRoute('_worker/after-controller', fn () => 'ok');
        $this->addWorkerRoute('_worker/controller', FailedRequestController::class . '@index');

        $route = null;
        $controller = null;

        $app['events']->listen(\Laravel\Octane\Events\RequestHandled::class, function ($event) use (&$route, &$controller) {
            if ($event->request->path() === '_worker/controller') {
                $route = $event->request->route();
                $controller = $route->controller;

                throw new RuntimeException('failure after the kernel answered');
            }
        });

        [$failed] = $this->dispatchWorkerRequests(Request::create('/_worker/controller', 'GET'));

        $this->assertInstanceOf(RuntimeException::class, $failed, 'the request must fail after the kernel answered it');
        $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
        $this->assertInstanceOf(FailedRequestController::class, $controller, 'the route must have held its controller when the request failed');

        $this->dispatchWorkerRequests(Request::create('/_worker/after-controller', 'GET'));

        $this->assertNull($route->controller, 'the failed request\'s controller must not stay on its route');
    }
}
