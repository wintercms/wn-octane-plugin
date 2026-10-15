<?php

namespace Winter\Octane\Tests;

use ReflectionClass;
use Winter\Octane\Classes\ResetsRequestState;
use System\Tests\Bootstrap\PluginTestCase;

/**
 * Guards the reset manifest against drift.
 *
 * ResetsRequestState clears a set of per-request static caches by reflection, which keeps the change
 * to a single place instead of adding a reset method to a dozen unrelated classes. The cost is that a
 * renamed or removed property would silently stop being reset, reintroducing a cross-request leak
 * with no failing test.
 *
 * These tests convert that silence into a failure: every entry in the manifest must still name a real
 * static property, and every manager the reset invokes must still declare the reset method.
 */
class StaticCacheManifestTest extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        /*
         * ResetsRequestState remembers which classes use a manifested trait for the life of the
         * worker. That memo has to be cleared here or a class declared by one test would still be
         * recorded, and the cursor into get_declared_classes() would skip classes declared by the
         * next one.
         */
        foreach (['traitUsers' => [], 'examinedClassCount' => 0] as $property => $value) {
            (new ReflectionClass(ResetsRequestState::class))->getProperty($property)->setValue(null, $value);
        }
    }

    protected function manifest(): array
    {
        $reflection = new ReflectionClass(ResetsRequestState::class);

        return $reflection->getConstant('STATIC_CACHES');
    }

    protected function traitManifest(): array
    {
        $reflection = new ReflectionClass(ResetsRequestState::class);

        return $reflection->getConstant('TRAIT_STATIC_CACHES');
    }

    public function testTheManifestIsNotEmpty()
    {
        $this->assertNotEmpty(
            $this->manifest(),
            'An empty manifest means no static cache is being reset at the request boundary.'
        );
    }

    /**
     * Every manifest entry must name a class that exists and a property that is actually static.
     */
    public function testEveryManifestedPropertyExistsAndIsStatic()
    {
        $problems = [];

        foreach ($this->manifest() as $class => $properties) {
            if (trait_exists($class)) {
                $problems[] = sprintf(
                    '%s is a trait and belongs in TRAIT_STATIC_CACHES; assigning a trait static is a '
                    . 'no-op because each using class holds its own copy',
                    $class
                );
                continue;
            }

            if (!class_exists($class)) {
                $problems[] = sprintf('%s does not exist', $class);
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($properties as $property => $value) {
                if (!$reflection->hasProperty($property)) {
                    $problems[] = sprintf('%s::$%s does not exist', $class, $property);
                    continue;
                }

                if (!$reflection->getProperty($property)->isStatic()) {
                    $problems[] = sprintf('%s::$%s is not static', $class, $property);
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "The reset manifest has drifted from the code it resets:\n  " . implode("\n  ", $problems)
        );
    }

    /**
     * The reset value must equal the property's declared default exactly.
     *
     * Type compatibility is not enough. Several of these caches use null as their "rebuild on next
     * read" sentinel; resetting one of those to [] is type-compatible and silently disables the
     * rebuild, so the cache serves emptiness for the rest of the worker's life. An earlier version
     * of this test allowed any reset value where the default was null, and that loophole let
     * exactly that bug ship for the view-global and mail-layout caches.
     */
    public function testResetValuesMatchTheDeclaredDefaults()
    {
        $problems = [];

        foreach ($this->manifest() + $this->traitManifest() as $class => $properties) {
            if (!class_exists($class) && !trait_exists($class)) {
                continue;
            }

            $defaults = (new ReflectionClass($class))->getDefaultProperties();

            foreach ($properties as $property => $value) {
                if (!array_key_exists($property, $defaults)) {
                    continue;
                }

                if ($defaults[$property] !== $value) {
                    $problems[] = sprintf(
                        '%s::$%s defaults to %s but is reset to %s; the reset must restore the '
                        . 'declared default, which is the value the class treats as "not yet built"',
                        $class,
                        $property,
                        var_export($defaults[$property], true),
                        var_export($value, true)
                    );
                }
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    /**
     * The same rule for the manager and auth manager manifests. The auth manager's address is the
     * one deliberate exception: its default is a placeholder, and null tells the next read that no
     * address has been recorded yet.
     */
    public function testManagerResetValuesMatchTheDeclaredDefaults()
    {
        $reflection = new ReflectionClass(ResetsRequestState::class);
        $manifest = $reflection->getConstant('MANAGER_STATE');
        $manifest[\Winter\Storm\Auth\Manager::class] = array_diff_key(
            $reflection->getConstant('AUTH_MANAGER_STATE'),
            ['ipAddress' => true]
        );
        $problems = [];

        foreach ($manifest as $class => $properties) {
            $defaults = (new ReflectionClass($class))->getDefaultProperties();

            foreach ($properties as $property => $value) {
                if (array_key_exists($property, $defaults) && $defaults[$property] !== $value) {
                    $problems[] = sprintf(
                        '%s::$%s defaults to %s but is reset to %s',
                        $class,
                        $property,
                        var_export($defaults[$property], true),
                        var_export($value, true)
                    );
                }
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    /**
     * Every manager property the reset clears must still exist, or a rename in core would silently
     * stop the reset rather than fail.
     */
    public function testManagerStateManifestMatchesCore()
    {
        $manifest = (new \ReflectionClass(ResetsRequestState::class))->getConstant('MANAGER_STATE');

        foreach ($manifest as $manager => $properties) {
            $this->assertTrue(class_exists($manager), $manager . ' no longer exists.');

            foreach (array_keys($properties) as $property) {
                $this->assertTrue(
                    property_exists($manager, $property),
                    $manager . '::$' . $property . ' no longer exists.'
                );
            }
        }
    }

    /**
     * The theme caches are cleared through the public API rather than the manifest, because only the
     * in-memory copy should be discarded; the external cache entries have their own invalidation.
     */
    public function testThemeExposesAMemoryOnlyCacheReset()
    {
        $method = new \ReflectionMethod(\Cms\Classes\Theme::class, 'resetCache');

        $this->assertTrue($method->isStatic());
        $this->assertSame(
            'memoryOnly',
            $method->getParameters()[0]->getName(),
            'Theme::resetCache() must keep its memory-only option, so the request boundary does not '
            . 'discard the persistent theme cache.'
        );
    }

    /**
     * Every trait manifest entry must name a real trait that declares the property as static.
     */
    public function testEveryManifestedTraitPropertyExistsAndIsStatic()
    {
        $problems = [];

        foreach ($this->traitManifest() as $trait => $properties) {
            if (!trait_exists($trait)) {
                $problems[] = sprintf('%s is not a trait', $trait);
                continue;
            }

            $reflection = new ReflectionClass($trait);

            foreach ($properties as $property => $value) {
                if (!$reflection->hasProperty($property)) {
                    $problems[] = sprintf('%s::$%s does not exist', $trait, $property);
                    continue;
                }

                if (!$reflection->getProperty($property)->isStatic()) {
                    $problems[] = sprintf('%s::$%s is not static', $trait, $property);
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "The trait reset manifest has drifted from the code it resets:\n  " . implode("\n  ", $problems)
        );
    }

    /**
     * Assigning a trait's static property does not reach the classes using the trait.
     *
     * This is the reason TRAIT_STATIC_CACHES exists at all, and it is asserted here so that the
     * separation is not "simplified" back into one manifest by someone who assumes a trait static
     * behaves like an inherited one.
     */
    public function testAssigningATraitStaticDoesNotReachTheUsingClass()
    {
        ManifestTraitUser::seed(['seeded']);

        $property = (new ReflectionClass(ManifestFixtureTrait::class))->getProperty('cache');
        $property->setValue(null, ['reset-through-the-trait']);

        $this->assertSame(
            ['seeded'],
            ManifestTraitUser::peek(),
            'Assigning through the trait reached the using class. If PHP ever changes this, '
            . 'TRAIT_STATIC_CACHES can be folded back into STATIC_CACHES.'
        );
    }

    /**
     * The reset must actually clear the property on a class that uses a manifested trait, which is
     * what the trait path in ResetsRequestState is for.
     *
     * A class is declared here for each manifested trait rather than relying on whichever real users
     * happen to be autoloaded when this runs. Plugins are free to use these traits, so the guarantee
     * being asserted is "any class using the trait gets reset", and a freshly declared user tests
     * exactly that. It also keeps the test meaningful for a trait with no users inside core.
     */
    public function testTheResetClearsTraitStaticsOnUsingClasses()
    {
        $subjects = [];

        foreach ($this->traitManifest() as $trait => $properties) {
            $class = $this->declareUserOf($trait);

            foreach ($properties as $property => $expected) {
                $reflected = (new ReflectionClass($class))->getProperty($property);
                $reflected->setValue(null, ['dirty' => 'dirty']);

                $subjects[] = [$trait, $class, $property, $expected];
            }
        }

        $this->assertNotEmpty($subjects, 'The trait manifest is empty, so this test proves nothing.');

        $this->invokeReset();

        foreach ($subjects as [$trait, $class, $property, $expected]) {
            $this->assertSame(
                $expected,
                (new ReflectionClass($class))->getProperty($property)->getValue(),
                sprintf('A class using %s did not have $%s reset', $trait, $property)
            );
        }
    }

    /**
     * Declares a class that uses the given trait and returns its name.
     *
     * @param class-string $trait
     * @return class-string
     */
    protected function declareUserOf(string $trait, string $discriminator = ''): string
    {
        $class = 'ManifestTraitUser_' . md5($trait . static::class . $discriminator);

        if (!class_exists($class, false)) {
            eval(sprintf('class %s { use \\%s; }', $class, $trait));
        }

        return $class;
    }


    /**
     * A class using a manifested trait that is declared after the first reset must still be found.
     *
     * resolveTraitUsers() remembers how far into get_declared_classes() the last pass reached, so that
     * only classes autoloaded since are examined. Autoloading means real trait users routinely appear
     * after the worker's first operation, so a cursor that skipped them would leave those classes
     * permanently unreset — and silently, since nothing else observes the memo.
     *
     * Scope worth being explicit about: this covers a class declared *between* two passes, which is the
     * case that actually occurs. It does not distinguish the two ways the cursor could be advanced,
     * because a class appearing *during* a pass would need something in the loop to autoload, and
     * nothing there does. That part of resolveTraitUsers() is defensive and has no failing test.
     */
    public function testATraitUserDeclaredAfterTheFirstResetIsStillFound()
    {
        // Advances the cursor past every class currently declared.
        $this->invokeReset();

        $subjects = [];

        foreach ($this->traitManifest() as $trait => $properties) {
            $class = $this->declareUserOf($trait, 'declared-late');

            foreach ($properties as $property => $expected) {
                (new ReflectionClass($class))->getProperty($property)->setValue(null, ['dirty' => 'dirty']);
                $subjects[] = [$class, $property, $expected];
            }
        }

        $this->assertNotEmpty($subjects, 'The trait manifest is empty, so this test proves nothing.');

        $this->invokeReset();

        foreach ($subjects as [$class, $property, $expected]) {
            $this->assertSame(
                $expected,
                (new ReflectionClass($class))->getProperty($property)->getValue(),
                sprintf('%s::$%s was declared after the first pass and was not reset', $class, $property)
            );
        }
    }

    /**
     * Runs only the static cache portion of the reset, so this test does not depend on the rest of
     * the request boundary being satisfiable outside a worker.
     */
    protected function invokeReset(): void
    {
        $listener = new ResetsRequestState();
        $method = new \ReflectionMethod($listener, 'resetStaticCaches');
        $method->invoke($listener);
    }
}

/**
 * Fixtures for testAssigningATraitStaticDoesNotReachTheUsingClass. They exist so the assertion is
 * about PHP's semantics rather than about whichever Winter trait happens to be manifested.
 */
trait ManifestFixtureTrait
{
    protected static $cache = [];

    public static function peek(): array
    {
        return static::$cache;
    }

    public static function seed(array $value): void
    {
        static::$cache = $value;
    }
}

class ManifestTraitUser
{
    use ManifestFixtureTrait;
}
