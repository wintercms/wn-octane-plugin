<?php

namespace Winter\Octane\Tests;

use ReflectionClass;
use ReflectionProperty;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Octane\Classes\ResetsRequestState;

/**
 * Fails when Winter core or Storm gains state that lives for the whole worker and that the reset
 * neither clears nor explicitly accepts.
 *
 * StaticCacheManifestTest proves the reset's manifest matches the code it names, but it cannot see
 * a static or a singleton the manifest does not name. A new per-request cache in core is invisible
 * under PHP-FPM and leaks between users in a worker. This test discovers every static property and
 * every Singleton class in core and Storm, and requires each one to be either reset or listed in
 * ACCEPTED below with the reason it is safe to keep for the life of the worker.
 *
 * A Singleton is checked property by property as well. Listing the class only says that someone
 * looked at it once; a per-user property added to it later would otherwise pass unnoticed. Every
 * instance property of every Singleton, inherited ones included, must be reset or be listed in
 * ACCEPTED_PROPERTIES. BaseContainerDiscoveryTest does the same for the objects that are not
 * Singletons but are resolved into the worker's base container.
 */
class CoreStateDiscoveryTest extends PluginTestCase
{
    use ReportsDeprecationsToPhpUnit;

    /**
     * State the reset clears outside its manifests, keyed by Class::$property, Class->property or
     * Class, with where.
     *
     * @var array<string, string>
     */
    protected const RESET_ELSEWHERE = [
        'Backend\Classes\BackendController::$action' => 'ResetsRequestState::resetManagers()',
        'Backend\Classes\BackendController::$params' => 'ResetsRequestState::resetManagers()',
        'Cms\Classes\Theme::$activeThemeCache' => 'Theme::resetCache(true), from ResetsRequestState::resetStaticCaches()',
        'Cms\Classes\Theme::$editThemeCache' => 'Theme::resetCache(true), from ResetsRequestState::resetStaticCaches()',
        'System\Twig\Loader::$allowInclude' => 'ResetsRequestState::resetCoreState()',
        'Winter\Storm\Database\MemoryCache' => 'flushed by ResetsRequestState::resetCoreState()',
        'Winter\Storm\Database\MemoryCache->cache' => 'MemoryCache::flush(), from ResetsRequestState::resetCoreState()',
        'Winter\Storm\Database\MemoryCache->tableMap' => 'MemoryCache::flush(), from ResetsRequestState::resetCoreState()',
        'Winter\Storm\Halcyon\Model::$cache' => 'its in-memory stores are flushed by ResetsRequestState::flushHalcyonRequestCache()',
    ];

    /**
     * State that is safe to keep for the life of a worker, keyed by Class::$property or Class.
     *
     * @var array<string, string>
     */
    protected const ACCEPTED = [
        // Constants in all but name: defaults, limits and fixed lists.
        'Backend\Classes\AuthManager::$permissionDefaults' => 'fixed defaults merged into each permission definition',
        'Backend\Models\User::$loginAttribute' => 'fixed name of the login column',
        'Cms\Classes\CmsException::$errorCodes' => 'fixed map of error codes to types',
        'Cms\Models\ThemeData::$cacheTtl' => 'fixed cache lifetime',
        'System\Classes\CombineAssets::$cssExtensions' => 'fixed list of extensions',
        'System\Classes\CombineAssets::$jsExtensions' => 'fixed list of extensions',
        'System\Classes\MediaLibraryItem::$audioExtensions' => 'fixed list of extensions',
        'System\Classes\MediaLibraryItem::$imageExtensions' => 'fixed list of extensions',
        'System\Classes\MediaLibraryItem::$videoExtensions' => 'fixed list of extensions',
        'System\Classes\SettingsManager::$itemDefaults' => 'fixed defaults merged into each settings item',
        'Winter\Storm\Argon\Argon::$createFromFormatFunction' => 'fixed name of the localized date function',
        'Winter\Storm\Argon\Argon::$formatFunction' => 'fixed name of the localized date function',
        'Winter\Storm\Argon\Argon::$parseFunction' => 'fixed name of the localized date function',
        'Winter\Storm\Auth\Models\Throttle::$attemptLimit' => 'fixed login attempt limit',
        'Winter\Storm\Auth\Models\Throttle::$suspensionTime' => 'fixed suspension length',
        'Winter\Storm\Auth\Models\User::$loginAttribute' => 'fixed name of the login column',
        'Winter\Storm\Database\Attach\File::$imageExtensions' => 'fixed list of extensions',
        'Winter\Storm\Database\Attributes\Relation::$relationTypes' => 'fixed map of relation types',
        'Winter\Storm\Database\Concerns\HasRelationships::$relationTypes' => 'fixed list of relation types',
        'Winter\Storm\Router\Router::$defaultValue' => 'fixed placeholder for an empty URL segment',
        'Winter\Storm\Support\Facades\Markdown::$cached' => 'fixed facade setting',
        'Winter\Storm\Support\Facades\Schema::$cached' => 'fixed facade setting',

        // Derived from code or configuration, which do not change while a worker runs.
        'Backend\Classes\Skin::$skinCache' => 'the back-end skin named in configuration',
        'Cms\Helpers\Cms::$actionExists' => 'whether a route exists; routes are registered once per worker',
        'Winter\Storm\Database\Concerns\HasRelationships::$resolvedNonRelationMethods' => 'per-class method lists read from the class definition',
        'Winter\Storm\Database\Concerns\HasRelationships::$resolvedRelationMethods' => 'per-class method lists read from the class definition',
        'Winter\Storm\Database\Model::$eventsBooted' => 'per-class flag that model events are wired',
        'Winter\Storm\Extension\ExtendableTrait::$extendableClassLoader' => 'the class loader',
        'Winter\Storm\Extension\ExtendableTrait::$extendableStaticMethods' => 'per-class method lists read from the class definition',
        'Winter\Storm\Halcyon\Model::$booted' => 'per-class boot flag',
        'Winter\Storm\Halcyon\Model::$eventsBooted' => 'per-class flag that model events are wired',
        'Winter\Storm\Halcyon\Model::$mutatorCache' => 'per-class accessor lists read from the class definition',

        // Registration made while the worker boots, which every request needs.
        'System\Classes\Asset\PackageManager::$callbacks' => 'package registration callbacks',
        'System\Classes\CombineAssets::$callbacks' => 'bundle and alias registration callbacks',
        'System\Traits\LazyOwnerAlias::$lazyAliases' => 'menu and settings owner aliases registered by plugins',
        'Winter\Storm\Extension\ExtendableTrait::$extendableCallbacks' => 'class extensions registered by plugins',
        'Winter\Storm\Extension\ExtensionTrait::$extensionCallbacks' => 'extension callbacks registered by plugins',

        // Services set once by a provider.
        'Winter\Storm\Halcyon\Model::$dispatcher' => 'the event dispatcher, set by a provider at boot',
        'Winter\Storm\Halcyon\Model::$resolver' => 'the datasource resolver, set by a provider at boot',
        'Winter\Storm\Database\Traits\ArraySource::$arraySourceConnection' => 'an in-memory database built from the model\'s own fixed records',

        // Set and cleared around a single call.
        'Winter\Storm\Extension\ExtensionTrait::$extendableStaticCalledClass' => 'set for one static extension call and cleared after it, including when it throws (storm#262)',

        // Decided once, from the request the worker boots with.
        'System\Classes\PluginManager::$noInit' => 'set at boot for privileged routes; this plugin is elevated, so its reset runs either way',

        // Used only by updates and migrations, which run from the console.
        'Winter\Storm\Database\Updater::$migrationCache' => 'migration file instances',

        // Declared but no longer used.
        'Backend\Classes\AuthManager::$instance' => 'the Singleton trait keeps the instance in the container, not here',

        // Singletons whose state is registration or configuration.
        'Cms\Classes\MediaLibrary' => 'deprecated alias of System\Classes\MediaLibrary',
        'Cms\Classes\ThemeManager' => 'no instance state',
        'System\Classes\Asset\BundleManager' => 'registered bundles',
        'System\Classes\Asset\PackageManager' => 'registered packages',
        'System\Classes\CombineAssets' => 'registered aliases, bundles and filters, and settings read from configuration',
        'System\Classes\ComposerManager' => 'the class loader and the autoload pools it was given',
        'System\Classes\MarkupManager' => 'markup tags registered by plugins',
        'System\Classes\MediaLibrary' => 'storage settings read from configuration; listings are cached in the cache store',
        'System\Classes\PluginManager' => 'the plugins loaded at boot',
        'System\Helpers\Cache' => 'no instance state',
    ];

    /**
     * Instance properties of Singletons that are safe to keep for the life of a worker, keyed by
     * Class->property.
     *
     * A property a Singleton inherits can be listed against the class that declares it, and the entry
     * then covers every Singleton that inherits it. A property the reset clears is listed in
     * ResetsRequestState::MANAGER_STATE (or AUTH_MANAGER_STATE for Storm's auth managers) instead.
     *
     * @var array<string, string>
     */
    protected const ACCEPTED_PROPERTIES = [
        // Backend\Classes\AuthManager. The user, impersonator, throttle, client address and session
        // flag are in MANAGER_STATE.
        'Backend\Classes\AuthManager->aliases' => 'permission owner aliases registered by plugins',
        'Backend\Classes\AuthManager->callbacks' => 'permission registration callbacks',
        'Backend\Classes\AuthManager->groupModel' => 'fixed model class',
        'Backend\Classes\AuthManager->permissionCache' => 'the registered permissions, sorted but not filtered by user; clearing it would make listPermissions() register every permission again',
        'Backend\Classes\AuthManager->permissionRoles' => 'the registered permissions grouped by role, built from permissionCache and not filtered by user',
        'Backend\Classes\AuthManager->permissions' => 'permissions registered by core and plugins',
        'Backend\Classes\AuthManager->requireActivation' => 'fixed setting',
        'Backend\Classes\AuthManager->sessionKey' => 'fixed session key',
        'Backend\Classes\AuthManager->throttleModel' => 'fixed model class',
        'Backend\Classes\AuthManager->userModel' => 'fixed model class',

        // Backend\Classes\NavigationManager. The menus and the menu context are in MANAGER_STATE.
        'Backend\Classes\NavigationManager->aliases' => 'menu owner aliases registered by plugins',
        'Backend\Classes\NavigationManager->callbacks' => 'navigation registration callbacks',
        'Backend\Classes\NavigationManager->contextSidenavPartials' => 'side menu partials registered at boot by the System provider; a controller that registers one sets the same value on every request',
        'Backend\Classes\NavigationManager->pluginManager' => 'the plugin manager',

        // Backend\Classes\WidgetManager. The permission-filtered report widgets are in MANAGER_STATE.
        'Backend\Classes\WidgetManager->formWidgetCallbacks' => 'form widget registration callbacks',
        'Backend\Classes\WidgetManager->formWidgetHints' => 'short codes of the registered form widgets',
        'Backend\Classes\WidgetManager->formWidgets' => 'form widgets registered by core and plugins; not filtered by user',
        'Backend\Classes\WidgetManager->pluginManager' => 'the plugin manager',
        'Backend\Classes\WidgetManager->reportWidgetCallbacks' => 'report widget registration callbacks',

        // Cms\Classes\ComponentManager. The resolved component details are in MANAGER_STATE.
        'Cms\Classes\ComponentManager->callbacks' => 'component registration callbacks',
        'Cms\Classes\ComponentManager->classMap' => 'components registered by plugins, keyed by class',
        'Cms\Classes\ComponentManager->codeMap' => 'components registered by plugins, keyed by code',
        'Cms\Classes\ComponentManager->pluginMap' => 'the plugin that registered each component',

        // System\Classes\Asset\BundleManager and PackageManager.
        'System\Classes\Asset\BundleManager->defaultPackages' => 'fixed list of packages per bundle',
        'System\Classes\Asset\BundleManager->registeredBundles' => 'bundles registered by plugins',
        'System\Classes\Asset\PackageManager->compilableConfigs' => 'fixed map of compiler configuration files',
        'System\Classes\Asset\PackageManager->packageJson' => 'the project package.json, set by the asset console commands',
        'System\Classes\Asset\PackageManager->packages' => 'packages registered by plugins and themes',

        // System\Classes\CombineAssets.
        'System\Classes\CombineAssets->aliases' => 'asset aliases registered by plugins',
        'System\Classes\CombineAssets->bundles' => 'asset bundles registered by plugins',
        'System\Classes\CombineAssets->filters' => 'asset filters registered by plugins',
        'System\Classes\CombineAssets->localPath' => 'the base path, set in init()',
        'System\Classes\CombineAssets->storagePath' => 'the combiner cache path, set in init()',
        'System\Classes\CombineAssets->useCache' => 'read from configuration in init()',
        'System\Classes\CombineAssets->useDeepHashing' => 'read from configuration in init()',
        'System\Classes\CombineAssets->useMinify' => 'read from configuration in init()',

        // System\Classes\ComposerManager.
        'System\Classes\ComposerManager->classMapPool' => 'autoload entries already given to the class loader',
        'System\Classes\ComposerManager->includeFilesPool' => 'autoload entries already given to the class loader',
        'System\Classes\ComposerManager->loader' => 'the class loader',
        'System\Classes\ComposerManager->namespacePool' => 'autoload entries already given to the class loader',
        'System\Classes\ComposerManager->psr4Pool' => 'autoload entries already given to the class loader',

        // System\Classes\MailManager. The template cache and the render mode are in MANAGER_STATE.
        'System\Classes\MailManager->callbacks' => 'mail template registration callbacks',
        'System\Classes\MailManager->registeredLayouts' => 'mail layouts registered by plugins',
        'System\Classes\MailManager->registeredPartials' => 'mail partials registered by plugins',
        'System\Classes\MailManager->registeredTemplates' => 'mail templates registered by plugins',

        // System\Classes\MarkupManager.
        'System\Classes\MarkupManager->callbacks' => 'markup tag registration callbacks',
        'System\Classes\MarkupManager->items' => 'markup tags registered by plugins; not filtered by user',
        'System\Classes\MarkupManager->pluginManager' => 'the plugin manager',

        // System\Classes\MediaLibrary, which Cms\Classes\MediaLibrary extends.
        'System\Classes\MediaLibrary->cacheKey' => 'fixed cache key',
        'System\Classes\MediaLibrary->ignoreNames' => 'read from configuration in init()',
        'System\Classes\MediaLibrary->ignorePatterns' => 'read from configuration in init()',
        'System\Classes\MediaLibrary->storageDisk' => 'read from configuration in init()',
        'System\Classes\MediaLibrary->storageFolder' => 'read from configuration in init()',
        'System\Classes\MediaLibrary->storageFolderNameLength' => 'read from configuration in init()',
        'System\Classes\MediaLibrary->storagePath' => 'read from configuration in init()',

        // System\Classes\PluginManager.
        'System\Classes\PluginManager->activeReplacementMap' => 'plugin replacements, resolved at boot',
        'System\Classes\PluginManager->app' => 'the base application',
        'System\Classes\PluginManager->booted' => 'boot flag',
        'System\Classes\PluginManager->normalizedMap' => 'plugin codes, loaded at boot',
        'System\Classes\PluginManager->pluginFlags' => 'enabled and disabled plugins, read at boot; a change needs a worker reload, as a code change does',
        'System\Classes\PluginManager->pluginRecords' => 'plugin database records, read at boot',
        'System\Classes\PluginManager->plugins' => 'the plugins loaded at boot',
        'System\Classes\PluginManager->registered' => 'registration flag',
        'System\Classes\PluginManager->registrationMethodCache' => 'what each plugin\'s register methods returned; plugins return the same definitions on every call',
        'System\Classes\PluginManager->replacementMap' => 'plugin replacements declared by plugins',

        // System\Classes\SettingsManager. The settings items and the settings context are in MANAGER_STATE.
        'System\Classes\SettingsManager->aliases' => 'settings owner aliases registered by plugins',
        'System\Classes\SettingsManager->callbacks' => 'settings registration callbacks',
        'System\Classes\SettingsManager->pluginManager' => 'the plugin manager',

        // System\Classes\UpdateManager. The messages and the product cache are in MANAGER_STATE.
        'System\Classes\UpdateManager->baseDirectory' => 'the base path, set in init()',
        'System\Classes\UpdateManager->disableCoreUpdates' => 'read from configuration in init()',
        'System\Classes\UpdateManager->key' => 'set only by setSecurity(), which core never calls',
        'System\Classes\UpdateManager->migrator' => 'the migrator, bound in init()',
        'System\Classes\UpdateManager->notesOutput' => 'console output, set only by the update console commands',
        'System\Classes\UpdateManager->pluginManager' => 'the plugin manager',
        'System\Classes\UpdateManager->repository' => 'the migration repository, bound in init()',
        'System\Classes\UpdateManager->secret' => 'set only by setSecurity(), which core never calls',
        'System\Classes\UpdateManager->tempDirectory' => 'the temporary path, set in init()',
        'System\Classes\UpdateManager->themeManager' => 'the theme manager',
        'System\Classes\UpdateManager->versionManager' => 'the version manager',

        // System\Classes\VersionManager. The file and database versions are in MANAGER_STATE.
        'System\Classes\VersionManager->notesOutput' => 'console output, set only by the update console commands',
        'System\Classes\VersionManager->pluginManager' => 'the plugin manager',
        'System\Classes\VersionManager->updater' => 'the update file runner, created in init()',

        // Winter\Storm\Auth\Manager, which Backend\Classes\AuthManager and front-end auth managers
        // extend. The user, impersonator, throttle, client address and session flag are in
        // AUTH_MANAGER_STATE.
        'Winter\Storm\Auth\Manager->groupModel' => 'fixed model class',
        'Winter\Storm\Auth\Manager->requireActivation' => 'fixed setting',
        'Winter\Storm\Auth\Manager->sessionKey' => 'fixed session key',
        'Winter\Storm\Auth\Manager->sessionManager' => 'the warmed session manager; only its cookie configuration is read',
        'Winter\Storm\Auth\Manager->throttleModel' => 'fixed model class',
        'Winter\Storm\Auth\Manager->useThrottle' => 'read from configuration in init()',
        'Winter\Storm\Auth\Manager->userModel' => 'fixed model class',

        // Winter\Storm\Database\MemoryCache. The cached queries are flushed, see RESET_ELSEWHERE.
        'Winter\Storm\Database\MemoryCache->enabled' => 'turned off once at boot for console processes',
    ];

    /**
     * Every static property and Singleton in core and Storm is either cleared by the reset or listed
     * in ACCEPTED.
     *
     * @return void
     */
    public function testEveryCoreStaticAndSingletonIsResetOrAccepted(): void
    {
        $uncovered = [];

        foreach ($this->discoverState() as $key) {
            if (!$this->isCovered($key) && !array_key_exists($key, static::ACCEPTED)) {
                $uncovered[] = $key;
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "Winter core or Storm holds state that lives for the whole worker, and the reset neither "
            . "clears it nor accepts it:\n  " . implode("\n  ", $uncovered) . "\n"
            . "For each one, read the core code. If it can hold per-request or per-user data, add it to "
            . "ResetsRequestState (STATIC_CACHES, TRAIT_STATIC_CACHES or MANAGER_STATE) with a test that "
            . "dispatches two requests. Otherwise add it to CoreStateDiscoveryTest::ACCEPTED with the "
            . "reason it is safe across requests."
        );
    }

    /**
     * Every instance property of every Singleton in core and Storm is either cleared by the reset or
     * listed in ACCEPTED_PROPERTIES.
     *
     * @return void
     */
    public function testEverySingletonPropertyIsResetOrAccepted(): void
    {
        $uncovered = [];

        foreach ($this->discoverSingletonProperties() as $key => $declaringKey) {
            if (!$this->isPropertyReset($key) && !$this->isPropertyAccepted($key, $declaringKey)) {
                $uncovered[] = $key;
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "A Winter core or Storm Singleton has instance properties that live for the whole worker, "
            . "and the reset neither clears them nor accepts them:\n  " . implode("\n  ", $uncovered) . "\n"
            . "For each one, read the core code. If it can hold per-request or per-user data, add it to "
            . "ResetsRequestState::MANAGER_STATE (or AUTH_MANAGER_STATE for a property of Storm's auth "
            . "manager) with its declared default, and a test that dispatches two requests through "
            . "PersistentWorkerTestCase. If it holds registration data, configuration or a service, add "
            . "it to CoreStateDiscoveryTest::ACCEPTED_PROPERTIES with the reason it is safe across requests."
        );
    }

    /**
     * Every entry in ACCEPTED, ACCEPTED_PROPERTIES and RESET_ELSEWHERE still names state that exists
     * in core or Storm, so the lists do not collect stale entries.
     *
     * @return void
     */
    public function testEveryAcceptedEntryStillExists(): void
    {
        $properties = $this->discoverSingletonProperties();
        $discovered = array_flip(array_merge($this->discoverState(), array_keys($properties), $properties));
        $stale = [];

        foreach (array_keys(static::ACCEPTED + static::RESET_ELSEWHERE + static::ACCEPTED_PROPERTIES) as $key) {
            if (!isset($discovered[$key])) {
                $stale[] = $key;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "These entries no longer exist in core or Storm. Remove them from CoreStateDiscoveryTest:\n  "
            . implode("\n  ", $stale)
        );
    }

    /**
     * No entry in ACCEPTED or ACCEPTED_PROPERTIES is also cleared by the reset, so each piece of
     * state has one clear owner.
     *
     * @return void
     */
    public function testNoStateIsBothResetAndAccepted(): void
    {
        $both = [];

        foreach (array_keys(static::ACCEPTED) as $key) {
            if ($this->isCovered($key)) {
                $both[] = $key;
            }
        }

        foreach (array_keys(static::ACCEPTED_PROPERTIES) as $key) {
            if ($this->isPropertyReset($key)) {
                $both[] = $key;
            }
        }

        $this->assertSame([], $both, 'Remove these from ACCEPTED or ACCEPTED_PROPERTIES; the reset already clears them.');
    }

    /**
     * Every static property (Class::$property) and every Singleton class (Class) in core and Storm.
     *
     * @return list<string>
     */
    protected function discoverState(): array
    {
        $state = [];

        foreach ($this->discoverClasses() as $class) {
            if (!class_exists($class) && !trait_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // A worker never runs console commands while it serves requests.
            if ($reflection->isSubclassOf(\Illuminate\Console\Command::class)) {
                continue;
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class
                    || $this->comesFromTrait($reflection, $property->getName())
                ) {
                    continue;
                }

                $state[] = $class . '::$' . $property->getName();
            }

            if (!$reflection->isTrait() && $this->usesSingleton($reflection)) {
                $state[] = $class;
            }
        }

        $state = array_values(array_unique($state));
        sort($state);

        return $state;
    }

    /**
     * Every instance property of every Singleton class in core and Storm, inherited ones included,
     * as Class->property => DeclaringClass->property.
     *
     * Each Singleton is its own container binding, so a property is reported against every Singleton
     * that has it: resetting it on the parent's instance does not reach a subclass's instance.
     *
     * @return array<string, string>
     */
    protected function discoverSingletonProperties(): array
    {
        $singleton = new ReflectionClass(\Winter\Storm\Support\Traits\Singleton::class);
        $properties = [];

        foreach ($this->discoverState() as $key) {
            if (strpos($key, '::$') !== false) {
                continue;
            }

            foreach ((new ReflectionClass($key))->getProperties() as $property) {
                // The trait's own members are the same on every Singleton.
                if ($property->isStatic() || $singleton->hasProperty($property->getName())) {
                    continue;
                }

                $properties[$key . '->' . $property->getName()]
                    = $property->getDeclaringClass()->getName() . '->' . $property->getName();
            }
        }

        ksort($properties);

        return $properties;
    }

    /**
     * Class and trait names declared in core's modules and in Storm, leaving out tests, migrations
     * and seeders, which a worker never runs while serving requests.
     *
     * @return list<string>
     */
    protected function discoverClasses(): array
    {
        $directories = [
            base_path('modules'),
            base_path('vendor/winter/storm/src'),
        ];
        $classes = [];

        foreach ($directories as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());

                if (substr($path, -4) !== '.php' || preg_match('#/(tests|updates|database)/#', $path)) {
                    continue;
                }

                $classes = array_merge($classes, $this->declaredNames(file_get_contents($path)));
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * The fully qualified names of the classes and traits a file declares.
     *
     * @param string $source The PHP source of one file.
     * @return list<string>
     */
    protected function declaredNames(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $names = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($next = $index + 1; isset($tokens[$next]); $next++) {
                    if ($tokens[$next] === ';' || $tokens[$next] === '{') {
                        break;
                    }

                    if (is_array($tokens[$next]) && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace .= $tokens[$next][1];
                    }
                }
            }
            elseif ($token[0] === T_CLASS || $token[0] === T_TRAIT) {
                $previous = $index - 1;

                while (isset($tokens[$previous]) && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                    $previous--;
                }

                // Skip Foo::class and anonymous classes.
                if (isset($tokens[$previous]) && is_array($tokens[$previous])
                    && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true)
                ) {
                    continue;
                }

                for ($next = $index + 1; isset($tokens[$next]); $next++) {
                    if (is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                        $names[] = ltrim($namespace . '\\' . $tokens[$next][1], '\\');
                        break;
                    }

                    if (!is_array($tokens[$next]) || $tokens[$next][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Whether a static property a class reports as its own was copied in from one of its traits,
     * in which case it is reported once, against the trait.
     *
     * @param \ReflectionClass<object> $class
     * @param string $property
     * @return bool
     */
    protected function comesFromTrait(ReflectionClass $class, string $property): bool
    {
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasProperty($property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the class or any of its parents uses Storm's Singleton trait.
     *
     * @param \ReflectionClass<object> $class
     * @return bool
     */
    protected function usesSingleton(ReflectionClass $class): bool
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            if (in_array(\Winter\Storm\Support\Traits\Singleton::class, $current->getTraitNames(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the reset clears this state: through one of its manifests, through
     * resetAuthManagers() for any Storm auth manager, or through RESET_ELSEWHERE.
     *
     * @param string $key A Class::$property or Class key, as discoverState() reports it.
     * @return bool
     */
    protected function isCovered(string $key): bool
    {
        if (array_key_exists($key, static::RESET_ELSEWHERE)) {
            return true;
        }

        if (strpos($key, '::$') === false) {
            return array_key_exists($key, $this->manifest('MANAGER_STATE'))
                || is_a($key, \Winter\Storm\Auth\Manager::class, true);
        }

        [$class, $property] = explode('::$', $key, 2);

        foreach (['STATIC_CACHES', 'TRAIT_STATIC_CACHES'] as $manifest) {
            if (array_key_exists($property, $this->manifest($manifest)[$class] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the reset clears this Singleton property: through MANAGER_STATE for its class, through
     * AUTH_MANAGER_STATE for any Storm auth manager, or through RESET_ELSEWHERE.
     *
     * @param string $key A Class->property key, as discoverSingletonProperties() reports it.
     * @return bool
     */
    protected function isPropertyReset(string $key): bool
    {
        if (array_key_exists($key, static::RESET_ELSEWHERE)) {
            return true;
        }

        [$class, $property] = explode('->', $key, 2);

        if (array_key_exists($property, $this->manifest('MANAGER_STATE')[$class] ?? [])) {
            return true;
        }

        return is_a($class, \Winter\Storm\Auth\Manager::class, true)
            && array_key_exists($property, (new \ReflectionClassConstant(ResetsRequestState::class, 'AUTH_MANAGER_STATE'))->getValue());
    }

    /**
     * Whether ACCEPTED_PROPERTIES lists this Singleton property, against its own class or against the
     * class that declares it.
     *
     * @param string $key A Class->property key.
     * @param string $declaringKey The same property, keyed by the class that declares it.
     * @return bool
     */
    protected function isPropertyAccepted(string $key, string $declaringKey): bool
    {
        return array_key_exists($key, static::ACCEPTED_PROPERTIES)
            || array_key_exists($declaringKey, static::ACCEPTED_PROPERTIES);
    }

    /**
     * Reads one of the reset's manifest constants.
     *
     * @param string $name The constant name, such as STATIC_CACHES.
     * @return array<string, array<string, mixed>>
     */
    protected function manifest(string $name): array
    {
        return (new \ReflectionClassConstant(ResetsRequestState::class, $name))->getValue();
    }
}
