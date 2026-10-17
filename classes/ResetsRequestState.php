<?php namespace Winter\Octane\Classes;

use Illuminate\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Log;
use System\Classes\PluginManager;
use System\Twig\Loader as TwigLoader;
use Throwable;
use Winter\Octane\Contracts\ResetsWorkerState;
use Winter\Storm\Database\MemoryCache as QueryMemoryCache;
use Winter\Storm\Halcyon\MemoryCacheManager;
use Winter\Storm\Halcyon\MemoryRepository;
use Winter\Storm\Halcyon\Model as HalcyonModel;

/**
 * Discards the state a single operation produced, so it cannot reach the next one.
 *
 * Laravel Octane clones the application container per operation and throws the clone away
 * afterwards, which covers container bindings made during the operation. It does not cover static
 * properties, nor mutable state inside objects that were resolved into the base container before
 * the first request, and those are where Winter keeps most of its request-scoped data.
 *
 * This listener is attached to the start of every operation rather than the end. Cleaning up
 * afterwards is not sufficient on its own: an exception that escapes the HTTP kernel skips
 * ApplicationGateway::terminate(), so RequestTerminated — and with it every listener registered
 * against Octane's OperationTerminated contract — never fires. Running at the start guarantees a
 * clean slate even after an operation that failed partway through.
 *
 * Everything here must be idempotent, and must not discard registration data such as navigation
 * definitions, permission callbacks or extension registries. Those are built once per worker, and
 * dropping them would leave the worker permanently degraded rather than merely stale.
 *
 * @package winter/wn-octane-plugin
 */
class ResetsRequestState
{
    /**
     * Handle an Octane operation event.
     *
     * @param mixed $event
     * @return void
     */
    public function handle($event): void
    {
        $sandbox = $event->sandbox ?? ($event->app ?? null);
        $base = $event->app ?? $sandbox;

        if (!$sandbox instanceof Application) {
            return;
        }

        /*
         * On the WorkerErrorOccurred path, NOTHING here may throw. That event is dispatched from
         * Worker::handleWorkerError(), inside the catch block of Worker::handle(); an exception
         * escaping a listener there escapes the worker loop itself and kills the process with no
         * graceful retirement. Each step is guarded individually — one failing step must not
         * abandon the rest of the cleanup — and the logging itself is guarded, because a broken
         * log channel throwing here would reopen exactly the hole this path closes. The next
         * RequestReceived, which is unguarded, is what surfaces a persistent fault.
         */
        if ($event instanceof \Laravel\Octane\Events\WorkerErrorOccurred) {
            foreach ($this->resetSteps($base, $sandbox, false) as $step) {
                try {
                    $step();
                }
                catch (Throwable $ex) {
                    $this->logQuietly(
                        'Winter.Octane: part of the request-state reset failed while the worker '
                        . 'was already handling an error: ' . $ex->getMessage(),
                        $ex
                    );
                }
            }

            return;
        }

        foreach ($this->resetSteps($base, $sandbox, true) as $step) {
            $step();
        }
    }

    /**
     * The steps that discard the state the previous operation produced, in order.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @param \Illuminate\Foundation\Application $sandbox
     * @param bool $throwOnPluginFailure Whether a failed plugin reset fails the operation.
     * @return list<callable(): void>
     */
    protected function resetSteps($base, Application $sandbox, bool $throwOnPluginFailure): array
    {
        return [
            fn () => $this->forgetExecutionContext($base, $sandbox),
            fn () => $this->resetCoreState(),
            fn () => $this->resetManagers($base, $sandbox),
            fn () => $this->resetStaticCaches(),
            fn () => $this->restoreMassAssignmentGuard(),
            fn () => $this->restoreExceptionRenderListeners($base),
            fn () => $this->restoreYamlProcessor($base),
            fn () => $this->resetSharedViewData($base, $sandbox),
            fn () => $this->rollBackTransactions($base, $sandbox),
            fn () => $this->flushRouteController($sandbox),
            fn () => $this->resetPlugins($throwOnPluginFailure),
        ];
    }

    /**
     * Log an error without ever letting the logger's own failure escape.
     *
     * Used only on the worker-error path, where an escaping exception — including one thrown by
     * a misconfigured log handler — would kill the worker loop outright.
     *
     * @param string $message
     * @param \Throwable $ex
     * @return void
     */
    protected function logQuietly(string $message, Throwable $ex): void
    {
        try {
            Log::error($message, ['exception' => $ex]);
        }
        catch (Throwable $logFailure) {
            // The log channel itself is broken; there is nowhere safe left to report to.
        }
    }

    /**
     * Messages already logged by warnOnce(), so a reset step that cannot run is reported once per
     * worker rather than on every operation.
     *
     * @var array<string, true>
     */
    protected static array $warned = [];

    /**
     * Log a warning the first time it occurs in this worker.
     *
     * @param string $message
     * @return void
     */
    protected function warnOnce(string $message): void
    {
        if (isset(static::$warned[$message])) {
            return;
        }

        static::$warned[$message] = true;

        try {
            Log::warning($message);
        }
        catch (Throwable $logFailure) {
            // The log channel itself is broken; there is nowhere safe left to report to.
        }
    }

    /**
     * Forget the resolved execution context from both containers.
     *
     * The context is derived from the request path and cached as a singleton, so the first request
     * a worker serves would otherwise decide whether every later request is treated as a back-end
     * one. It has to be forgotten from the base container as well as the sandbox: a worker boots
     * from the CLI, so the value resolved during boot comes from the synthetic console request and
     * lives on the base container that every sandbox is cloned from.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @param \Illuminate\Foundation\Application $sandbox
     * @return void
     */
    protected function forgetExecutionContext($base, Application $sandbox): void
    {
        $sandbox->forgetInstance('execution.context');

        if ($base !== null && $base !== $sandbox) {
            $base->forgetInstance('execution.context');
        }
    }

    /**
     * Reset framework-level state held outside the managers.
     *
     * @return void
     */
    protected function resetCoreState(): void
    {
        $this->flushHalcyonRequestCache();

        /*
         * Storm caches duplicate queries for the rest of the process, and only a write made through
         * this process clears it. Storm turns the cache off for console processes, but a worker is
         * not one, so without this a row changed by another worker is never read again here.
         */
        QueryMemoryCache::instance()->flush();

        /*
         * Defensive: the gate gives arbitrary local file inclusion when left open, and a Winter
         * without the Engine fix leaves it open after a failed template load.
         */
        TwigLoader::$allowInclude = false;
    }

    /**
     * Empty Halcyon's in-memory record cache on every store the cache managers have resolved.
     *
     * Halcyon makes the application's cache manager a MemoryCacheManager and hands the same object
     * to Halcyon\Model. Both are held for the life of the worker. A plugin may give Halcyon\Model a
     * different manager, which leaves the application's own stores holding every value they read,
     * so both managers are flushed. Only the in-memory layer is cleared; the external stores they
     * wrap keep their own invalidation rules.
     *
     * @return void
     */
    protected function flushHalcyonRequestCache(): void
    {
        $managers = [];

        if (property_exists(HalcyonModel::class, 'cache')) {
            $managers[] = (new \ReflectionProperty(HalcyonModel::class, 'cache'))->getValue();
        }
        else {
            $this->warnOnce('Winter.Octane: Halcyon\\Model::$cache no longer exists, so the Halcyon request cache is not reset between requests.');
        }

        $container = \Illuminate\Container\Container::getInstance();

        if ($container->resolved('cache')) {
            $managers[] = $container->make('cache');
        }

        $flushed = [];

        foreach ($managers as $manager) {
            if (!$manager instanceof MemoryCacheManager || isset($flushed[spl_object_id($manager)])) {
                continue;
            }

            $flushed[spl_object_id($manager)] = true;

            if (!(new \ReflectionObject($manager))->hasProperty('stores')) {
                $this->warnOnce('Winter.Octane: the Halcyon cache manager has no $stores property, so the Halcyon request cache is not reset between requests.');

                return;
            }

            foreach ((new \ReflectionProperty($manager, 'stores'))->getValue($manager) as $store) {
                if ($store instanceof MemoryRepository) {
                    $store->flushInternalCache();
                }
            }
        }
    }

    /**
     * Reset the core managers that cache per-user data.
     *
     * These are all resolved into the base container while providers register, so the sandbox never
     * discards them. Each clears only what an operation produced; registration callbacks, aliases
     * and definitions are preserved, since those are built once per worker.
     *
     * Only managers that have actually been resolved are touched. Singleton::instance() binds and
     * make()s on demand, so calling it unconditionally would construct every manager at every request
     * boundary — including ones this install never uses. A manager that was never resolved holds no
     * state to discard, so skipping it is also the correct result and not merely the cheaper one.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @param \Illuminate\Foundation\Application $sandbox
     * @return void
     */
    protected function resetManagers($base, Application $sandbox): void
    {
        foreach (static::MANAGER_STATE as $manager => $properties) {
            if (class_exists($manager, false) && $this->isResolved($manager, $base, $sandbox)) {
                $this->resetInstanceProperties($manager::instance(), $properties);
            }
        }

        $this->resetAuthManagers($base ?? $sandbox);
        /*
         * Backend dispatch writes the resolved action and route parameters onto the controller
         * class itself, so a later request would otherwise attempt the previous request's action.
         */
        if (class_exists(\Backend\Classes\BackendController::class, false)) {
            // @phpstan-ignore assign.propertyType (declared as string, but null is its initial value)
            \Backend\Classes\BackendController::$action = null;
            \Backend\Classes\BackendController::$params = [];
        }
    }

    /**
     * Clear the resolved user from every Storm auth manager the container holds.
     *
     * Storm's Auth\Manager::getUser() returns the user it already holds without checking the
     * session, so a manager that outlives an operation authenticates the next request as the
     * previous user. The back-end manager is listed in MANAGER_STATE; this also covers the
     * front-end manager from Winter.User and any other plugin's subclass, which this plugin cannot
     * list by name.
     *
     * @param \Illuminate\Foundation\Application $container
     * @return void
     */
    protected function resetAuthManagers(Application $container): void
    {
        if (!class_exists(\Winter\Storm\Auth\Manager::class, false)
            || !property_exists(\Illuminate\Container\Container::class, 'instances')
        ) {
            return;
        }

        $instances = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instances'))->getValue($container);

        foreach ($instances as $instance) {
            if ($instance instanceof \Winter\Storm\Auth\Manager) {
                $this->resetInstanceProperties($instance, static::AUTH_MANAGER_STATE);
            }
        }
    }

    /**
     * The per-request properties of Winter\Storm\Auth\Manager, with their declared defaults.
     *
     * @var array<string, mixed>
     */
    protected const AUTH_MANAGER_STATE = [
        'user' => null,
        'impersonator' => null,
        'throttle' => [],
        'viaRemember' => false,
        'ipAddress' => '0.0.0.0',
        'useSession' => true,
    ];

    /**
     * Assign the given values to properties of an object, skipping any it does not declare.
     *
     * @param object $instance
     * @param array<string, mixed> $properties
     * @return void
     */
    protected function resetInstanceProperties(object $instance, array $properties): void
    {
        $reflection = new \ReflectionObject($instance);

        foreach ($properties as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($instance, $value);
            }
        }
    }

    /**
     * Whether a singleton has already been resolved in either container.
     *
     * The base container is the one that matters, since that is where module providers resolve these
     * managers and therefore where a leaked instance would live. The sandbox is checked too because it
     * is a shallow clone: a manager first resolved inside the current operation appears there.
     *
     * @param class-string $manager
     * @param \Illuminate\Foundation\Application|null $base
     * @param \Illuminate\Foundation\Application $sandbox
     * @return bool
     */
    protected function isResolved(string $manager, $base, Application $sandbox): bool
    {
        return ($base !== null && $base->resolved($manager)) || $sandbox->resolved($manager);
    }

    /**
     * Per-request caches held in static properties, as class => [property => value after reset].
     *
     * These are memoized for the duration of one request by design, but a static property outlives
     * the operation that filled it. Reaching them by reflection keeps the change to a single place
     * rather than adding a reset method to a dozen unrelated classes; the accompanying manifest test
     * asserts every entry still exists, so a rename fails a test instead of silently disabling a
     * reset.
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected const STATIC_CACHES = [
        // Error masks. applyMask() and removeMask() are expected to balance, but an exception
        // between them leaves a mask applied to the next operation's errors.
        \Winter\Storm\Exception\ErrorHandler::class => ['activeMask' => null, 'maskLayers' => []],

        // Front-end request context. The CMS controller assigns itself here on construction, so a
        // stale instance exposes the previous request's page, layout, theme and variables to
        // anything reaching Controller::getController() off its own call path.
        \Cms\Classes\Controller::class => ['instance' => null],

        // Component properties for every page, layout and partial, memoized from the cache. Left set,
        // an edit served by another worker never reaches this one, and the cache TTL never applies.
        \Cms\Classes\CmsCompoundObject::class => ['objectComponentPropertyMap' => null],

        // Documented as a request-level cache for CMS objects. Core no longer writes it, but anything
        // that does expects it to be empty at the start of a request.
        \Cms\Classes\ObjectMemoryCache::class => ['cache' => []],

        // Theme settings models and their values, keyed by theme.
        \Cms\Models\ThemeData::class => ['instances' => []],

        // Parsed file contents and source markers, which survive edits to those files.
        \Cms\Classes\CodeParser::class => ['cache' => []],

        // Whether the base Snowboard asset has been emitted. Left set, later responses omit it.
        \Cms\Twig\SnowboardNode::class => ['baseLoaded' => false],

        // Database-backed settings models. A save in one request is otherwise invisible to the next.
        \System\Behaviors\SettingsModel::class => ['instances' => []],

        // Event and configuration derived image sources. Reset to an empty array rather than null to
        // match the declared default, so the next read does not have to tolerate a type it never
        // sees under PHP-FPM.
        \System\Classes\ImageResizer::class => ['availableSources' => []],

        // Request-shared view globals, which can be returned to a different user. The reset value
        // must be null, not []: null is the property's "rebuild on next read" sentinel, and an
        // empty array would be served as the (empty) globals for the rest of the worker's life.
        \System\Helpers\View::class => ['globalVarCache' => null],

        // Database-backed mail layouts, parameters and plugin versions. MailLayout's cache uses
        // null as its rebuild sentinel for the same reason as the view globals above.
        \System\Models\MailLayout::class => ['codeCache' => null],
        \System\Models\Parameter::class => ['cache' => []],
        \System\Models\PluginVersion::class => ['versionCache' => null],

        // Backend user preferences. The behavior declares its own $instances cache, separate from
        // the SettingsModel storage reset above, keyed by record code only — but the record it
        // holds belongs to one user, so a stale entry serves user A's preferences to user B and
        // lets B's save overwrite A's row.
        \Backend\Behaviors\UserPreferencesModel::class => ['instances' => []],

        // Set when a repeater "add item" AJAX call is handled, and read by every Repeater instance
        // in the process to decide whether to skip processItems(). Left set, one user's add-row
        // action disables repeater processing for every later request the worker serves.
        \Backend\FormWidgets\Repeater::class => ['onAddItemCalled' => false],

        // Per-user auth preference values, keyed by user and preference. Not a cross-user leak on
        // its own, but under PHP-FPM the cache lived for one request; per-worker it would grow
        // without bound and serve values gone stale in the database indefinitely.
        \Winter\Storm\Auth\Models\Preferences::class => ['cache' => []],

        // The backend subclass REDECLARES the static, so it owns a separate storage slot that
        // late static binding routes the inherited methods to; resetting the parent above does
        // not touch it.
        \Backend\Models\UserPreference::class => ['cache' => []],
    ];

    /**
     * Request-derived state on core singletons, as class => [property => value after reset].
     *
     * These are all resolved into the base container while providers register, so the sandbox never
     * discards them. Only state an operation produced is listed; registration callbacks, aliases and
     * definitions are built once per worker and are preserved. The manifest test asserts every entry
     * still exists, so a rename in core fails a test instead of silently disabling a reset.
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected const MANAGER_STATE = [
        // The main and side menus, filtered by the first user's permissions.
        \Backend\Classes\NavigationManager::class => [
            'items' => null,
            'quickActions' => null,
            'contextOwner' => null,
            'contextMainMenuItemCode' => null,
            'contextSideMenuItemCode' => null,
        ],

        // listReportWidgets() removes entries the current user lacks permission for from the cache.
        \Backend\Classes\WidgetManager::class => ['reportWidgets' => null],

        // Settings items, filtered by the first user's permissions.
        \System\Classes\SettingsManager::class => [
            'items' => null,
            'groupedItems' => null,
            'contextOwner' => null,
            'contextItemCode' => null,
        ],

        // Database-backed templates, and a render mode toggle that a throw mid-render leaves set.
        \System\Classes\MailManager::class => ['templateCache' => [], 'isHtmlRenderMode' => false],

        // Resolved component details, including localized strings.
        \Cms\Classes\ComponentManager::class => ['detailsCache' => null],

        // Whether the current theme has a media player partial, keyed by partial name only. Left set,
        // a partial added later is never used, and a site that switches themes per request applies
        // one theme's answer to another.
        \Cms\Classes\MediaViewHelper::class => ['playerPartialFlags' => []],

        // Plugin versions read from version.yaml files and the database. Left set, an update run by
        // another worker is invisible here, and an update started here works from stale versions.
        \System\Classes\VersionManager::class => [
            'fileVersions' => null,
            'databaseVersions' => null,
            'databaseHistory' => null,
        ],

        // Migration and seeder messages, which otherwise accumulate across update runs, and the
        // product details memoized from the cache store.
        \System\Classes\UpdateManager::class => ['messages' => [], 'productCache' => null],

        // The resolved user, impersonator and throttle models. The client address is recorded again
        // for each HTTP request by ResolveAuthIpAddress.
        \Backend\Classes\AuthManager::class => [
            'user' => null,
            'impersonator' => null,
            'throttle' => [],
            'viaRemember' => false,
            'ipAddress' => '0.0.0.0',
            // once() turns sessions off and, on a Storm without the fix, leaves them off when
            // authentication throws.
            'useSession' => true,
        ],
    ];

    /**
     * Per-request caches declared by a trait, as trait => [property => value after reset].
     *
     * These cannot be reset the way STATIC_CACHES entries are. A trait's static property is a
     * template: every class the trait is flattened into receives its own independent copy, so
     * assigning to the property through the trait is silently a no-op. The using classes have to be
     * found and reset individually, which resolveTraitUsers() does.
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected const TRAIT_STATIC_CACHES = [
        // A class-global current page name written during URL generation. Left set, the next
        // operation generates URLs against the previous operation's page.
        \Cms\Traits\UrlMaker::class => ['urlPageName' => null],

        // Backend widget preferences. The in-memory key is not scoped to the user, so a stale entry
        // returns one user's widget preferences to the next.
        \Backend\Traits\PreferenceMaker::class => ['preferenceCache' => []],

        // The validation factory, memoized on first use. 'validator' is not pre-warmed, so the first
        // request resolves it in its own sandbox, and the memo would keep that factory, with the
        // sandbox container Octane flushes after the request, for the rest of the worker's life.
        \Winter\Storm\Halcyon\Traits\Validation::class => ['validator' => null],
    ];

    /**
     * Classes that use a trait in TRAIT_STATIC_CACHES, as trait => list of classes.
     *
     * Resolved lazily and kept for the life of the worker, since a class cannot stop using a trait.
     *
     * @var array<class-string, list<class-string>>
     */
    protected static array $traitUsers = [];

    /**
     * How many declared classes resolveTraitUsers() has already examined.
     *
     * @var int
     */
    protected static int $examinedClassCount = 0;

    /**
     * Clear the per-request caches held in static properties.
     *
     * @return void
     */
    protected function resetStaticCaches(): void
    {
        foreach (static::STATIC_CACHES as $class => $properties) {
            if (!class_exists($class, false)) {
                continue;
            }

            $this->resetStaticProperties($class, $properties);
        }

        $this->resetTraitStaticCaches();

        /*
         * The active and edit theme are memoized in statics and also cached externally. Only the
         * in-memory copy is cleared: the external entries have their own invalidation, and forgetting
         * them every operation would defeat the cache entirely.
         */
        if (class_exists(\Cms\Classes\Theme::class, false)) {
            \Cms\Classes\Theme::resetCache(true);
        }
    }

    /**
     * Clear the per-request caches declared by traits, on every class that uses one.
     *
     * @return void
     */
    protected function resetTraitStaticCaches(): void
    {
        $this->resolveTraitUsers();

        foreach (static::TRAIT_STATIC_CACHES as $trait => $properties) {
            foreach (static::$traitUsers[$trait] ?? [] as $class) {
                $this->resetStaticProperties($class, $properties);
            }
        }
    }

    /**
     * Find the classes using each trait in TRAIT_STATIC_CACHES, examining only classes not seen yet.
     *
     * Plugins are free to use these traits, so the set cannot be hard-coded, and autoloading means it
     * is not complete until the class in question has been touched. get_declared_classes() only ever
     * appends, so remembering how far the last pass got makes each subsequent operation examine just
     * the classes autoloaded since. The first pass, during the worker's first operation, is the only
     * one that walks the whole list.
     *
     * @return void
     */
    protected function resolveTraitUsers(): void
    {
        $declared = get_declared_classes();

        if (count($declared) === static::$examinedClassCount) {
            return;
        }

        $fresh = array_slice($declared, static::$examinedClassCount);

        foreach ($fresh as $class) {
            /*
             * Classifying one class must not abort the pass. Defensive, and known to be so: everything
             * reached here — class_parents(), class_uses(), ReflectionClass — is being asked about a
             * class that is already declared, so no throw is expected and no test demonstrates one. It
             * is guarded because of how it would fail if it ever did: the exception would escape the
             * request boundary and take the operation down, and every later class in the batch would go
             * unclassified. A class that cannot be reflected cannot hold a resettable static either, so
             * skipping it is the correct outcome rather than a swallowed error.
             */
            try {
                $uses = class_uses_recursive($class);

                foreach (static::TRAIT_STATIC_CACHES as $trait => $properties) {
                    if (!in_array($trait, $uses, true)) {
                        continue;
                    }

                    /*
                     * A subclass of a using class does not get its own copy of the static, it shares
                     * the storage declared on its ancestor, so only the class the trait was flattened
                     * into is worth recording. Resetting the subclass too would write the same slot
                     * twice.
                     */
                    if ($this->declaresAnyOf($class, array_keys($properties))) {
                        static::$traitUsers[$trait][] = $class;
                    }
                }
            }
            catch (Throwable $ex) {
                continue;
            }
        }

        /*
         * The snapshot taken above, not a fresh count. A class declared while this ran is therefore
         * picked up by the next pass, since it is beyond the snapshot rather than behind the cursor.
         */
        static::$examinedClassCount = count($declared);
    }

    /**
     * Whether the class itself declares any of the given properties, rather than inheriting them.
     *
     * @param class-string $class
     * @param list<string> $properties
     * @return bool
     */
    protected function declaresAnyOf(string $class, array $properties): bool
    {
        $reflection = new \ReflectionClass($class);

        foreach ($properties as $property) {
            if (!$reflection->hasProperty($property)) {
                continue;
            }

            if ($reflection->getProperty($property)->getDeclaringClass()->getName() === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assign the given values to static properties of a class, skipping any that do not apply.
     *
     * @param class-string $class
     * @param array<string, mixed> $properties
     * @return void
     */
    protected function resetStaticProperties(string $class, array $properties): void
    {
        $reflection = new \ReflectionClass($class);

        foreach ($properties as $property => $value) {
            if (!$reflection->hasProperty($property)) {
                continue;
            }

            $reflected = $reflection->getProperty($property);

            if (!$reflected->isStatic()) {
                continue;
            }

            $reflected->setValue(null, $value);
        }
    }

    /**
     * State recorded the first time the reset sees it, before any request code has run, keyed by
     * the reset step that restores it. A missing key means the step has not recorded its state yet.
     *
     * @var array<string, mixed>
     */
    protected static array $bootState = [];

    /**
     * The event whose listeners restoreExceptionRenderListeners() restores.
     */
    protected const EXCEPTION_RENDER_EVENT = 'exception.beforeRender';

    /**
     * Put Eloquent's mass-assignment protection back to its boot-time state.
     *
     * Storm's Updater turns the protection off around each migration and seeder, and turns it back
     * on only when the script returns, so a migration that throws leaves every model in the worker
     * open to mass assignment. The boot-time state is restored rather than "guarded", so a site that
     * unguards its models while booting keeps the behavior it has under PHP-FPM.
     *
     * @return void
     */
    protected function restoreMassAssignmentGuard(): void
    {
        if (!array_key_exists('unguarded', static::$bootState)) {
            static::$bootState['unguarded'] = \Illuminate\Database\Eloquent\Model::isUnguarded();

            return;
        }

        \Illuminate\Database\Eloquent\Model::unguard((bool) static::$bootState['unguarded']);
    }

    /**
     * Put the exception.beforeRender listeners back to the ones registered while the worker booted.
     *
     * BackendController::run() adds a listener to this event on every back-end request, so that an
     * abort(404) or abort(403) inside a back-end controller renders the back-end's error page. Under
     * PHP-FPM the listener dies with the request. The dispatcher lives in the base container, so in
     * a worker the listeners pile up, one per back-end request, each holding its controller, and a
     * stale one renders the back-end's access denied page for a later front-end 403. Restoring the
     * boot-time listeners at the start of each operation leaves the current request's own listener
     * in place for that request.
     *
     * A listener a plugin adds to this event during a request lasts for that request only, as under
     * PHP-FPM. One added once per worker, after boot, has to be added while booting instead.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @return void
     */
    protected function restoreExceptionRenderListeners($base): void
    {
        if ($base === null || !$base->resolved('events')) {
            return;
        }

        $events = $base->make('events');

        if (!$events instanceof \Winter\Storm\Events\Dispatcher
            || !property_exists(\Illuminate\Events\Dispatcher::class, 'listeners')
            || !property_exists(\Winter\Storm\Events\Dispatcher::class, 'sorted')
        ) {
            $this->warnOnce('Winter.Octane: the event dispatcher is not a Storm dispatcher with $listeners and $sorted properties, so the exception.beforeRender listeners added by back-end requests are not removed between requests.');

            return;
        }

        $listeners = new \ReflectionProperty(\Illuminate\Events\Dispatcher::class, 'listeners');
        $all = $listeners->getValue($events);
        $current = $all[static::EXCEPTION_RENDER_EVENT] ?? null;

        if (!array_key_exists('exceptionRenderListeners', static::$bootState)) {
            static::$bootState['exceptionRenderListeners'] = $current;

            return;
        }

        $baseline = static::$bootState['exceptionRenderListeners'];

        if ($current === $baseline) {
            return;
        }

        if ($baseline === null) {
            unset($all[static::EXCEPTION_RENDER_EVENT]);
        }
        else {
            $all[static::EXCEPTION_RENDER_EVENT] = $baseline;
        }

        $listeners->setValue($events, $all);

        // Storm caches the listeners sorted by priority, and would keep dispatching the stale list.
        $sorted = new \ReflectionProperty(\Winter\Storm\Events\Dispatcher::class, 'sorted');
        $cache = $sorted->getValue($events);
        unset($cache[static::EXCEPTION_RENDER_EVENT]);
        $sorted->setValue($events, $cache);
    }

    /**
     * Put the YAML parser's processor back to its boot-time value.
     *
     * Yaml::withProcessor() swaps the processor on the shared parser for the length of a callback,
     * and does not swap it back when the callback throws. A worker resolves the parser into the base
     * container while it boots, so a failed version.yaml parse would leave VersionYamlProcessor in
     * place, and every later parse in the worker would be run through it. Only a parser in the base
     * container outlives an operation, so one that only a sandbox resolved is left alone.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @return void
     */
    protected function restoreYamlProcessor($base): void
    {
        if ($base === null || !$base->resolved('parse.yaml')) {
            return;
        }

        $yaml = $base->make('parse.yaml');

        if (!$yaml instanceof \Winter\Storm\Parse\Yaml || !property_exists(\Winter\Storm\Parse\Yaml::class, 'processor')) {
            $this->warnOnce('Winter.Octane: the YAML parser has no $processor property, so a processor left by a failed parse is not removed between requests.');

            return;
        }

        $processor = new \ReflectionProperty(\Winter\Storm\Parse\Yaml::class, 'processor');
        $current = $processor->getValue($yaml);

        if (!array_key_exists('yamlProcessor', static::$bootState)) {
            static::$bootState['yamlProcessor'] = $current;

            return;
        }

        if ($current !== static::$bootState['yamlProcessor']) {
            $processor->setValue($yaml, static::$bootState['yamlProcessor']);
        }
    }

    /**
     * The view factory's shared data as it stood when the worker served its first operation, or
     * null before the first pass has recorded it.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $sharedViewBaseline = null;

    /**
     * Restore the view factory's shared data to its boot-time state.
     *
     * Resetting System\Helpers\View::$globalVarCache alone is not enough: that static is only a
     * memo of the view factory's real shared-data array, and the factory itself is resolved into
     * the base container at boot, so anything a request pushed in through View::share() survives
     * the sandbox being discarded.
     *
     * The factory cannot simply be emptied either — providers share legitimate data at boot (the
     * app name, for one) that every later operation is entitled to. The first reset runs before
     * any request code has executed AND before Octane's own listeners fire (this listener is
     * attached at a higher priority), so the entries present then are exactly the boot-time set —
     * not yet polluted by the per-operation objects Octane injects, such as the request sandbox
     * that GiveNewApplicationInstanceToViewFactory writes into shared['app']. Each later reset
     * restores that snapshot wholesale, and Octane's listeners then re-inject the CURRENT
     * operation's objects on top, so nothing dead is ever served. Restoring values, not just
     * dropping added keys, matters: a request that OVERWRITES a boot-time key (a per-site app
     * name, say) would otherwise hand its value to every later request on the worker.
     *
     * The consequence is that a share made during a request lives exactly as long as under
     * PHP-FPM: one operation. A provider first booted mid-request that shares data once must
     * share per request instead (or use a view composer) to work on a worker; a boundary reset
     * cannot tell that share apart from request state, and keeping it would keep the leaks too.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @param \Illuminate\Foundation\Application $sandbox
     * @return void
     */
    protected function resetSharedViewData($base, Application $sandbox): void
    {
        /*
         * Only a factory in the base container outlives an operation. One that only a sandbox
         * resolved is discarded with it, and snapshotting it would take a failed request's shares
         * as the baseline.
         */
        if ($base === null || !$base->resolved('view')) {
            return;
        }

        $factory = $base->make('view');

        if (static::$sharedViewBaseline === null) {
            static::$sharedViewBaseline = $factory->getShared();

            return;
        }

        $reflection = new \ReflectionObject($factory);

        if (!$reflection->hasProperty('shared')) {
            $this->warnOnce('Winter.Octane: the view factory has no $shared property, so shared view data is not reset between requests.');

            return;
        }

        $reflection->getProperty('shared')->setValue($factory, static::$sharedViewBaseline);
    }

    /**
     * Discard staged transaction callbacks and roll every connection back to depth zero.
     *
     * No stock Octane listener unwinds an abandoned transaction. Leaving one open holds the
     * connection's snapshot open for the next operation, and because `db.transactions` is one of
     * the services Octane pre-warms into the base container, its staged callback records outlive
     * the operation that registered them and would run against a later one.
     *
     * @param \Illuminate\Foundation\Application|null $base
     * @param \Illuminate\Foundation\Application $sandbox
     * @return void
     */
    protected function rollBackTransactions($base, Application $sandbox): void
    {
        if (!$sandbox->resolved('db')) {
            return;
        }

        $connections = $sandbox->make('db')->getConnections();

        /*
         * Attach a fresh transaction manager BEFORE rolling back. The rollback fires the previous
         * operation's rollback callbacks through the manager it registered them on, and by now
         * facades and auth resolve against the new operation's sandbox, so those callbacks would
         * act as the next user. Swapping first discards them unrun.
         */
        $manager = new DatabaseTransactionsManager();

        foreach ([$base, $sandbox] as $container) {
            if ($container !== null && $container->bound('db.transactions')) {
                $container->instance('db.transactions', $manager);
            }
        }

        foreach ($connections as $connection) {
            $connection->setTransactionManager($manager);
        }

        foreach ($connections as $connection) {
            while ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack(0);
                }
                catch (Throwable $ex) {
                    /*
                     * The connection cannot be returned to a known state, so it must not be handed
                     * to the next operation. Disconnecting forces a reconnect on next use, and the
                     * transaction depth goes back to zero: Laravel only resets it itself for a lost
                     * connection, and a depth above zero would make the next beginTransaction()
                     * issue a savepoint on the disconnected handle instead of reconnecting.
                     */
                    $connection->disconnect();

                    if (property_exists($connection, 'transactions')) {
                        (new \ReflectionProperty($connection, 'transactions'))->setValue($connection, 0);
                    }

                    break;
                }
            }
        }
    }

    /**
     * Clear the controller cached on the current route.
     *
     * ApplicationGateway::terminate() does this after a successful operation, but it is never
     * reached when an exception escapes the kernel, which leaves the previous operation's
     * controller instance attached to the route.
     *
     * @param \Illuminate\Foundation\Application $sandbox
     * @return void
     */
    protected function flushRouteController(Application $sandbox): void
    {
        if (!$sandbox->resolved('router')) {
            return;
        }

        $route = $sandbox->make('router')->getCurrentRoute();

        if ($route !== null && method_exists($route, 'flushController')) {
            $route->flushController();
        }
    }

    /**
     * Invoke the reset contract on every loaded plugin that provides it.
     *
     * Plugin register() and boot() run once per worker, so a plugin that caches request-derived data
     * has no other opportunity to clear it.
     *
     * A plugin qualifies by implementing ResetsWorkerState or simply by declaring resetWorkerState(), so
     * a plugin can support Octane without depending on this one.
     *
     * A plugin that throws aborts the whole reset and fails the operation. That is deliberate, and it
     * is the opposite of what the rest of this class does for classes it cannot reflect. The
     * difference is what is known: a class that cannot be reflected holds no resettable state, whereas
     * a plugin that declared itself resettable and then failed has left state behind whose owner is
     * unknown. Continuing would serve the operation from state that may belong to another user, which
     * is the failure this class exists to prevent, so the operation is failed instead.
     *
     * The consequence is intended too. Because the reset runs at the start of every operation, a
     * plugin that always throws makes the worker fail every request rather than quietly degrade, which
     * is what forces it to be fixed.
     *
     * The WorkerErrorOccurred path is the one place this throw does not propagate: there
     * $throwOnFailure is false, a failed plugin is logged, and the REMAINING plugins are still
     * reset — that event is dispatched from inside the worker loop's catch block, where an
     * escaping exception kills the process outright, and one broken plugin abandoning every
     * other plugin's cleanup would leave exactly the cross-user state this class exists to clear.
     *
     * @param bool $throwOnFailure Whether a failed plugin reset fails the operation.
     * @return void
     * @throws \RuntimeException
     */
    protected function resetPlugins(bool $throwOnFailure = true): void
    {
        $pluginManager = PluginManager::instance();

        foreach ($pluginManager->getPlugins() as $identifier => $plugin) {
            if (!is_object($plugin)
                || (!$plugin instanceof ResetsWorkerState && !method_exists($plugin, 'resetWorkerState'))
            ) {
                continue;
            }

            try {
                $plugin->resetWorkerState();
            }
            catch (Throwable $ex) {
                if ($throwOnFailure) {
                    throw new \RuntimeException(sprintf(
                        'Plugin %s failed to reset its worker state: %s',
                        $identifier,
                        $ex->getMessage()
                    ), 0, $ex);
                }

                $this->logQuietly(sprintf(
                    'Winter.Octane: plugin %s failed to reset its worker state while the worker '
                    . 'was already handling an error: %s',
                    $identifier,
                    $ex->getMessage()
                ), $ex);
            }
        }
    }
}
