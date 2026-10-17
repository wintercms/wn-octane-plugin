<?php

namespace Winter\Octane\Tests;

use Illuminate\Http\Request;
use ReflectionClass;
use Winter\Octane\Classes\ResetsRequestState;

/**
 * Every manifest entry must actually be cleared by a real dispatch, not merely exist.
 *
 * StaticCacheManifestTest proves the manifest matches the code it names: the classes exist, the
 * properties are static, the reset values equal the declared defaults. None of that proves the
 * reset RUNS against them. These tests dirty every manifested property, push a real request
 * through Octane's gateway, and read the properties back from inside the request, after the reset
 * has run and before anything else has had a chance to repopulate them.
 */
class ManifestResetDispatchTest extends PersistentWorkerTestCase
{
    /**
     * Every static in the class manifest is back at its reset value by the time a dispatched request
     * runs, after the test dirtied it.
     *
     * @return void
     */
    public function testEveryManifestedStaticIsClearedByADispatchedRequest(): void
    {
        $this->bootWorker();

        $manifest = (new ReflectionClass(ResetsRequestState::class))->getConstant('STATIC_CACHES');
        $subjects = [];

        foreach ($manifest as $class => $properties) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ($properties as $property => $resetValue) {
                $reflected = (new ReflectionClass($class))->getProperty($property);
                $reflected->setValue(null, $this->dirtyValueFor($resetValue));

                $subjects[] = [$class, $property, $resetValue];
            }
        }

        $this->assertNotEmpty($subjects, 'The manifest is empty, so this test proves nothing.');

        $observed = [];

        $this->addWorkerRoute('_worker/manifest-sweep', function () use ($subjects, &$observed) {
            foreach ($subjects as [$class, $property, $resetValue]) {
                $observed[$class . '::$' . $property] =
                    (new ReflectionClass($class))->getProperty($property)->getValue();
            }

            return 'ok';
        });

        $this->dispatchWorkerRequests(Request::create('/_worker/manifest-sweep', 'GET'));

        foreach ($subjects as [$class, $property, $resetValue]) {
            $this->assertSame(
                $resetValue,
                $observed[$class . '::$' . $property],
                sprintf(
                    '%s::$%s was still dirty when the request ran. The manifest names it, but the '
                    . 'dispatched reset did not clear it.',
                    $class,
                    $property
                )
            );
        }
    }

    /**
     * Every static in the trait manifest is cleared by a dispatched request, checked on a freshly
     * declared user of each trait.
     *
     * @return void
     */
    public function testEveryManifestedTraitStaticIsClearedByADispatchedRequest(): void
    {
        $this->bootWorker();

        $manifest = (new ReflectionClass(ResetsRequestState::class))->getConstant('TRAIT_STATIC_CACHES');
        $subjects = [];

        foreach ($manifest as $trait => $properties) {
            if (!trait_exists($trait)) {
                continue;
            }

            /*
             * A trait static lives on each using class, so the sweep needs a class to dirty and read.
             */
            $class = 'ManifestDispatchTraitUser_' . md5($trait);

            if (!class_exists($class, false)) {
                eval(sprintf('class %s { use \\%s; }', $class, $trait));
            }

            foreach ($properties as $property => $resetValue) {
                (new ReflectionClass($class))->getProperty($property)
                    ->setValue(null, $this->dirtyValueFor($resetValue));

                $subjects[] = [$class, $property, $resetValue];
            }
        }

        $this->assertNotEmpty($subjects, 'The trait manifest is empty, so this test proves nothing.');

        $observed = [];

        $this->addWorkerRoute('_worker/trait-sweep', function () use ($subjects, &$observed) {
            foreach ($subjects as [$class, $property, $resetValue]) {
                $observed[$class . '::$' . $property] =
                    (new ReflectionClass($class))->getProperty($property)->getValue();
            }

            return 'ok';
        });

        $this->dispatchWorkerRequests(Request::create('/_worker/trait-sweep', 'GET'));

        foreach ($subjects as [$class, $property, $resetValue]) {
            $this->assertSame(
                $resetValue,
                $observed[$class . '::$' . $property],
                sprintf('%s::$%s (via trait) was still dirty when the request ran.', $class, $property)
            );
        }
    }

    /**
     * Every property in the manager manifest no longer holds the value the test dirtied it with
     * when a dispatched request runs.
     *
     * The properties are dirtied on each manager's instance in the base container, which is the
     * instance every request sandbox shares.
     *
     * @return void
     */
    public function testEveryManifestedManagerPropertyIsClearedByADispatchedRequest(): void
    {
        $this->bootWorker();

        $manifest = (new ReflectionClass(ResetsRequestState::class))->getConstant('MANAGER_STATE');
        $subjects = [];

        foreach ($manifest as $manager => $properties) {
            if (!class_exists($manager)) {
                continue;
            }

            $instance = $manager::instance();

            foreach ($properties as $property => $resetValue) {
                $reflected = (new \ReflectionObject($instance))->getProperty($property);
                $dirty = $this->dirtyValueForProperty($reflected, $resetValue);
                $reflected->setValue($instance, $dirty);

                $subjects[] = [$manager, $property, $dirty];
            }
        }

        $this->assertNotEmpty($subjects, 'The manager manifest is empty, so this test proves nothing.');

        $observed = [];

        $this->addWorkerRoute('_worker/manager-sweep', function () use ($subjects, &$observed) {
            foreach ($subjects as [$manager, $property]) {
                $instance = $manager::instance();
                $observed[$manager . '::$' . $property] =
                    (new \ReflectionObject($instance))->getProperty($property)->getValue($instance);
            }

            return 'ok';
        });

        $this->dispatchWorkerRequests(Request::create('/_worker/manager-sweep', 'GET'));

        /*
         * Compared against the dirty value rather than the reset value: some properties are
         * legitimately set again before the route runs, such as the client address recorded by
         * ResolveAuthIpAddress.
         */
        foreach ($subjects as [$manager, $property, $dirty]) {
            $this->assertNotSame(
                $dirty,
                $observed[$manager . '::$' . $property],
                sprintf('%s::$%s was still dirty when the request ran.', $manager, $property)
            );
        }
    }

    /**
     * A dirty value that the property's declared type accepts.
     *
     * @param \ReflectionProperty $property The property the value will be written to.
     * @param mixed $resetValue The value the reset restores.
     * @return mixed
     */
    protected function dirtyValueForProperty(\ReflectionProperty $property, mixed $resetValue): mixed
    {
        $type = $property->getType();

        if ($type === null || $type instanceof \ReflectionNamedType && $type->getName() === 'array') {
            return $this->dirtyValueFor($resetValue);
        }

        $accepts = $type instanceof \ReflectionNamedType ? [$type->getName()] : array_map(
            fn ($named) => $named->getName(),
            $type instanceof \ReflectionUnionType ? $type->getTypes() : []
        );

        if (in_array('array', $accepts, true)) {
            return ['__octane_dirty__' => true];
        }

        if (in_array('string', $accepts, true)) {
            return '__octane_dirty__';
        }

        if (in_array('bool', $accepts, true)) {
            return !$resetValue;
        }

        return $this->dirtyValueFor($resetValue);
    }

    /**
     * A value of the same shape as the reset value, but distinguishable from it.
     *
     * @param mixed $resetValue The value the reset restores.
     * @return mixed
     */
    protected function dirtyValueFor(mixed $resetValue): mixed
    {
        if ($resetValue === null) {
            return ['__octane_dirty__' => true];
        }

        if (is_array($resetValue)) {
            return ['__octane_dirty__' => true];
        }

        if (is_bool($resetValue)) {
            return !$resetValue;
        }

        return '__octane_dirty__';
    }
}
