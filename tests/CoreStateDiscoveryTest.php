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
 */
class CoreStateDiscoveryTest extends PluginTestCase
{
    use ReportsDeprecationsToPhpUnit;

    /**
     * State the reset clears outside its manifests, keyed by Class::$property or Class, with where.
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
     * Every entry in ACCEPTED and RESET_ELSEWHERE still names state that exists in core or Storm, so
     * the lists do not collect stale entries.
     *
     * @return void
     */
    public function testEveryAcceptedEntryStillExists(): void
    {
        $discovered = array_flip($this->discoverState());
        $stale = [];

        foreach (array_keys(static::ACCEPTED + static::RESET_ELSEWHERE) as $key) {
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
     * No entry in ACCEPTED is also cleared by the reset, so each piece of state has one clear owner.
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

        $this->assertSame([], $both, 'Remove these from ACCEPTED; the reset already clears them.');
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
