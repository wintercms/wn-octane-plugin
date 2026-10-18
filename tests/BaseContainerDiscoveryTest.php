<?php

namespace Winter\Octane\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;

/**
 * Fails when an object that is not a Singleton is resolved into the worker's base container and
 * nothing accounts for it.
 *
 * Octane clones the base container for each operation and throws the clone away afterwards, so an
 * object first resolved during a request is discarded with it. An object resolved into the base
 * container, while the worker boots or by code that holds the base container during a request,
 * outlives every request instead. CoreStateDiscoveryTest audits core's Singletons property by
 * property, but a service bound with $app->singleton() (the flash bag, the Twig environment, the
 * HTML and form builders) is invisible to it.
 *
 * This test boots the worker the way production does, serves a front-end page, a front-end AJAX
 * request and back-end pages, signed out and signed in, and then requires every instance in the
 * base container to be handled by Octane, audited by CoreStateDiscoveryTest, or listed in
 * ACCEPTED_INSTANCES with the reason it is safe to keep for the life of the worker.
 */
class BaseContainerDiscoveryTest extends PersistentWorkerTestCase
{
    /**
     * Base container instances that Octane points at each operation's sandbox or replaces, beyond
     * the services in its warm and flush lists, keyed by container key.
     *
     * @var array<string, string>
     */
    protected const OCTANE_MANAGED = [
        'Illuminate\Container\Container' => 'the base application; Octane makes each operation\'s sandbox the current container',
        'Illuminate\Contracts\Http\Kernel' => 'GiveNewApplicationInstanceToHttpKernel gives it each operation\'s sandbox',
        'Laravel\Octane\Contracts\Client' => 'the worker\'s server client',
        'app' => 'the base application; Octane makes each operation\'s sandbox the current container',
        'mail.manager' => 'GiveNewApplicationInstanceToMailManager gives it each operation\'s sandbox and forgets its mailers',
        'octane' => 'Octane\'s own service',
        'queue' => 'GiveNewApplicationInstanceToQueueManager gives it each operation\'s sandbox',
        'request' => 'GiveNewRequestInstanceToApplication binds each request',
        'validator' => 'GiveNewApplicationInstanceToValidationFactory gives it each operation\'s sandbox',
    ];

    /**
     * Base container instances that are safe to keep for the life of a worker, keyed by container
     * key.
     *
     * @var array<string, string>
     */
    protected const ACCEPTED_INSTANCES = [
        // Registration and configuration made while the worker boots.
        'Illuminate\Contracts\Debug\ExceptionHandler' => 'report and render callbacks registered at boot',
        'Illuminate\Foundation\PackageManifest' => 'the package manifest, read from bootstrap/cache',
        'Winter\Storm\Support\ClassLoader' => 'the class loader',
        'date' => 'the date factory; its settings are static and come from configuration',
        'translation.loader' => 'translation paths and namespaces registered at boot',
        'view.engine.resolver' => 'the view engines registered at boot',

        // Services that keep no request data.
        'Illuminate\Foundation\MaintenanceModeManager' => 'the maintenance mode driver, which reads the maintenance state from storage on every check',
        'db.dongle' => 'the database driver helper',
        'validation.presence' => 'the database presence verifier; the validator sets its connection each time it uses it',

        // State that this plugin puts back to its boot-time value at the start of each operation.
        'events' => 'the listeners registered at boot; BackendController::run() adds an exception.beforeRender listener on every back-end request, and ResetsRequestState restores that event\'s listeners to the boot-time set',
        'parse.yaml' => 'the YAML parser; ResetsRequestState restores its processor to the boot-time one, since Yaml::withProcessor() leaves its processor in place when the callback throws',

        // State that is cleared per operation by something other than this plugin.
        'Illuminate\Foundation\Exceptions\Renderer\Listener' => 'the queries shown on the debug error page; Laravel clears them at every Octane operation, because the octane:start commands set LARAVEL_OCTANE',
        'halcyon' => 'the theme datasources; Theme::load() registers them again on each request, after the reset clears the theme cache',

        // Used only by updates and migrations, which run from the console.
        'migration.repository' => 'bound when the update manager is created',
        'migrator' => 'bound when the update manager is created',

        // Present only because this is a test.
        'Illuminate\Contracts\Console\Kernel' => 'resolved by the test case\'s migrations; a worker does not run console commands',
        'Illuminate\Testing\LoggedExceptionCollection' => 'bound only when running unit tests',
    ];

    /**
     * Base container instances that hold state which does cross requests today, keyed by container
     * key, with the leak. They are listed so the audit passes while the leak is open, and kept apart
     * from ACCEPTED_INSTANCES so they are not mistaken for safe. Fixing a leak means removing its
     * entry here and moving the instance to ACCEPTED_INSTANCES.
     *
     * @var array<string, string>
     */
    protected const KNOWN_LEAKS = [];

    /**
     * Builds the application the way Octane's ApplicationFactory builds a worker's, with this plugin
     * registered while providers register.
     *
     * The stock test application binds the Octane client only after it has booted, so the plugin's
     * back-end registrations never run and the back-end managers are first resolved inside a request
     * sandbox, where they would be discarded. Here the client is bound before the application
     * bootstraps, as Octane binds it, so the base container holds what a production worker's does.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication(): Application
    {
        if (!class_exists(\Laravel\Octane\ApplicationFactory::class)) {
            return parent::createApplication();
        }

        $app = require __DIR__ . '/../../../../bootstrap/app.php';

        /*
         * A fixture directory containing ONLY this plugin, as in ProductionBootOrderTest, so the
         * site's other plugins are not registered against an unmigrated in-memory database.
         */
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
     * Every instance in the base container after a worker has served traffic is handled by Octane,
     * audited by CoreStateDiscoveryTest, or listed in ACCEPTED_INSTANCES.
     *
     * @return void
     */
    public function testEveryBaseContainerInstanceIsResetOrAccepted(): void
    {
        $uncovered = [];

        foreach ($this->baseInstancesAfterTraffic() as $key => $instance) {
            if ($this->coverage($key, $instance) === null) {
                $uncovered[] = $key . ' (' . get_debug_type($instance) . ')';
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "These objects are in the worker's base container, so they outlive every request, and "
            . "nothing accounts for them:\n  " . implode("\n  ", $uncovered) . "\n"
            . "For each one, find where it is resolved into the base container and read its class. If "
            . "it can hold per-request or per-user data, clear that data in ResetsRequestState, or "
            . "forget the instance there, with a test that dispatches two requests through "
            . "PersistentWorkerTestCase. If it is resolved only because a request used the base "
            . "container by mistake, fix that instead. Otherwise add it to "
            . "BaseContainerDiscoveryTest::ACCEPTED_INSTANCES with the reason it is safe across requests."
        );
    }

    /**
     * Every entry in ACCEPTED_INSTANCES, KNOWN_LEAKS and OCTANE_MANAGED is still in the base
     * container after the same traffic, so the lists do not collect stale entries.
     *
     * @return void
     */
    public function testEveryAcceptedInstanceIsStillResolved(): void
    {
        $instances = $this->baseInstancesAfterTraffic();
        $stale = [];

        foreach (array_keys(static::ACCEPTED_INSTANCES + static::KNOWN_LEAKS + static::OCTANE_MANAGED) as $key) {
            if (!array_key_exists($key, $instances)) {
                $stale[] = $key;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "These entries are no longer in the base container. Remove them from BaseContainerDiscoveryTest:\n  "
            . implode("\n  ", $stale)
        );
    }

    /**
     * No entry in ACCEPTED_INSTANCES or KNOWN_LEAKS is also handled by Octane, and no instance is both
     * accepted and a known leak, so each instance has one clear owner.
     *
     * @return void
     */
    public function testNoInstanceIsBothManagedAndAccepted(): void
    {
        $both = array_values(array_intersect(
            array_keys(static::ACCEPTED_INSTANCES + static::KNOWN_LEAKS),
            $this->octaneManagedKeys()
        ));

        $this->assertSame([], $both, 'Remove these from BaseContainerDiscoveryTest; Octane already handles them.');
        $this->assertSame(
            [],
            array_keys(array_intersect_key(static::ACCEPTED_INSTANCES, static::KNOWN_LEAKS)),
            'These are listed as both accepted and a known leak. Keep each in one list.'
        );
    }

    /**
     * Serve the traffic a worker sees and return the base container's instances afterwards.
     *
     * The requests cover the front end, a front-end AJAX handler, the back-end sign-in page, and
     * back-end pages for a signed-in administrator, which build the menus, the settings and the
     * permission lists. Each must succeed, or the audit would only have seen an error page.
     *
     * @return array<string, mixed>
     */
    protected function baseInstancesAfterTraffic(): array
    {
        $app = $this->bootWorker();

        $admin = \Backend\Models\User::create([
            'login' => 'discovery',
            'email' => 'discovery@example.com',
            'password' => 'Discovery-Passw0rd',
            'password_confirmation' => 'Discovery-Passw0rd',
            'first_name' => 'Discovery',
            'last_name' => 'Test',
        ]);
        $admin->is_superuser = true;
        $admin->forceSave();

        /*
         * Signs the administrator in for a request, after the reset has signed out the previous one.
         * The reset is attached at the highest priority, so this default-priority listener runs
         * after it.
         */
        $signedIn = false;
        $app['events']->listen(\Laravel\Octane\Events\RequestReceived::class, function () use (&$signedIn, $admin, $app) {
            if ($signedIn) {
                AuthManager::instance($app)->setUser($admin);
            }
        });

        $ajax = Request::create('/ajax-test', 'POST');
        $ajax->headers->set('X-Requested-With', 'XMLHttpRequest');
        $ajax->headers->set('X-WINTER-REQUEST-HANDLER', 'onTest');
        $ajax->headers->set('X-WINTER-REQUEST-PARTIALS', '');

        $backend = '/' . trim((string) $app['config']->get('cms.backendUri', 'backend'), '/');

        $results = $this->dispatchWorkerRequests(
            Request::create('/', 'GET'),
            $ajax,
            Request::create($backend . '/backend/auth/signin', 'GET')
        );

        $signedIn = true;
        $results = array_merge($results, $this->dispatchWorkerRequests(
            Request::create($backend . '/backend', 'GET'),
            Request::create($backend . '/cms', 'GET')
        ));
        $signedIn = false;

        foreach ($results as $index => $result) {
            $this->assertFalse(
                $result instanceof Throwable,
                'Request ' . $index . ' failed: ' . ($result instanceof Throwable ? $result->getMessage() : '')
            );
            $this->assertSame(200, $result instanceof Throwable ? null : $result->getStatusCode(), 'Request ' . $index . ' did not succeed.');
        }

        return (new \ReflectionProperty(Container::class, 'instances'))->getValue($app);
    }

    /**
     * What accounts for a base container instance, or null when nothing does.
     *
     * @param string $key
     * @param mixed $instance
     * @return string|null
     */
    protected function coverage(string $key, mixed $instance): ?string
    {
        if (array_key_exists($key, static::ACCEPTED_INSTANCES)) {
            return static::ACCEPTED_INSTANCES[$key];
        }

        if (array_key_exists($key, static::KNOWN_LEAKS)) {
            return 'a known leak: ' . static::KNOWN_LEAKS[$key];
        }

        if (in_array($key, $this->octaneManagedKeys(), true)) {
            return 'handled by Octane';
        }

        if (is_scalar($instance)) {
            return 'a path or flag, which does not change unless it is bound again';
        }

        if (!is_object($instance)) {
            return null;
        }

        if ($instance instanceof \Illuminate\Console\Command) {
            return 'a console command; a worker does not run console commands';
        }

        if (strpos(get_class($instance), 'Laravel\\Octane\\Listeners\\') === 0) {
            return 'one of Octane\'s listeners';
        }

        if (in_array(\Winter\Storm\Support\Traits\Singleton::class, class_uses_recursive($instance), true)
            && preg_match('/^(Backend|Cms|System|Winter\\\\Storm)\\\\/', get_class($instance))
        ) {
            return 'a core Singleton, audited property by property by CoreStateDiscoveryTest';
        }

        return null;
    }

    /**
     * The container keys Octane handles: its warm and flush lists, and OCTANE_MANAGED.
     *
     * @return list<string>
     */
    protected function octaneManagedKeys(): array
    {
        $config = $this->app['config'];

        return array_values(array_unique(array_merge(
            array_filter((array) $config->get('octane.warm', []), 'is_string'),
            array_filter((array) $config->get('octane.flush', []), 'is_string'),
            array_keys(static::OCTANE_MANAGED)
        )));
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
