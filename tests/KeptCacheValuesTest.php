<?php

namespace Winter\Octane\Tests;

use Illuminate\Http\Request;
use Laravel\Octane\Events\WorkerErrorOccurred;
use System\Classes\PluginBase;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Contracts\KeepsWorkerCacheValues;

/**
 * A plugin can keep chosen in-memory cache values from one request to the next.
 *
 * Every test writes values into the memory layer of the file store in one request and reads that
 * layer in the next. The file store is used because Octane already flushes the array store between
 * operations.
 */
class KeptCacheValuesTest extends PersistentWorkerTestCase
{
    /**
     * A value the plugin accepts survives the reset, and every other value is flushed.
     *
     * @return void
     */
    public function testAKeptValueSurvivesAndAnUnkeptValueDoesNot(): void
    {
        $seen = $this->writeThenRead(new KeptCacheValuesPrefixPlugin($this->app), [
            'kept-scalar' => 'site name',
            'kept-false'  => false,
            'kept-array'  => ['a' => 1, 'b' => [true, 2.5, null, 'x']],
            'other'       => 'per-request',
        ]);

        $this->assertSame('site name', $seen['kept-scalar'] ?? null);
        $this->assertFalse($seen['kept-false'] ?? null);
        $this->assertSame(['a' => 1, 'b' => [true, 2.5, null, 'x']], $seen['kept-array'] ?? null);
        $this->assertArrayNotHasKey('other', $seen);
    }

    /**
     * A parsed YAML file stays in memory without any plugin asking, because its key names the file's
     * modification time, and an edited file is parsed again under a new key.
     *
     * @return void
     */
    public function testAParsedYamlFileIsKeptUntilTheFileChanges(): void
    {
        $file = sys_get_temp_dir() . '/winter-octane-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($file, "version: 1\n");
        touch($file, time() - 100);

        $key = fn () => 'yaml::' . $file . '-' . filemtime($file);
        $results = [];

        $this->app['config']->set('app.debug', false);
        $this->app['config']->set('cache.default', 'file');

        $this->addWorkerRoute('_worker/yaml', function () use ($file, $key, &$results) {
            $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
            clearstatcache();

            $results[] = [
                'in memory' => array_key_exists($key(), (new \ReflectionProperty($store, 'cache'))->getValue($store)),
                'parsed'    => \Winter\Storm\Support\Facades\Yaml::parseFile($file),
            ];

            return 'read';
        });

        try {
            $this->dispatchWorkerRequests(
                Request::create('/_worker/yaml', 'GET'),
                Request::create('/_worker/yaml', 'GET')
            );

            file_put_contents($file, "version: 2\n");
            touch($file, time() - 50);

            $this->dispatchWorkerRequests(Request::create('/_worker/yaml', 'GET'));
        }
        finally {
            @unlink($file);
        }

        $this->assertSame([
            ['in memory' => false, 'parsed' => ['version' => 1]],
            ['in memory' => true, 'parsed' => ['version' => 1]],
            ['in memory' => false, 'parsed' => ['version' => 2]],
        ], $results);
    }

    /**
     * An object is never kept, even inside an array, because every later request would share the
     * same instance. Neither is an array holding a PHP reference, which every copy shares. A string a driver serialized an object into is kept, since each read builds a
     * new object from it.
     *
     * @return void
     */
    public function testAnObjectValueIsNeverKept(): void
    {
        $shared = ['role' => 'guest'];
        $shared['alias'] = &$shared['role'];

        $cycle = ['a' => 1];
        $cycle['self'] = &$cycle;

        $seen = $this->writeThenRead(new KeptCacheValuesPrefixPlugin($this->app), [
            'kept-object'     => new \ArrayObject(['user' => 'a']),
            'kept-nested'     => ['ok' => 1, 'model' => new \stdClass()],
            'kept-closure'    => fn () => 'a',
            'kept-serialized' => serialize(new \ArrayObject(['user' => 'a'])),
            'kept-reference'  => $shared,
            'kept-cycle'      => $cycle,
        ]);

        $this->assertArrayNotHasKey('kept-object', $seen);
        $this->assertArrayNotHasKey('kept-nested', $seen);
        $this->assertArrayNotHasKey('kept-closure', $seen);
        $this->assertArrayNotHasKey('kept-reference', $seen);
        $this->assertArrayNotHasKey('kept-cycle', $seen);
        $this->assertSame(serialize(new \ArrayObject(['user' => 'a'])), $seen['kept-serialized'] ?? null);
    }

    /**
     * A plugin that throws drops the value it was asked about and is logged, and the reset and the
     * request still complete.
     *
     * @return void
     */
    public function testAThrowingPluginFlushesTheKeyWithoutBreakingTheReset(): void
    {
        $throwing = new class ($this->app) extends PluginBase
        {
            /**
             * Fails for one key and keeps the rest.
             *
             * @param string $key
             * @param mixed $value
             * @return bool
             */
            public function keepWorkerCacheValue(string $key, $value): bool
            {
                if ($key === 'kept-broken') {
                    throw new \RuntimeException('deliberate keep failure');
                }

                return true;
            }
        };

        $seen = $this->writeThenRead($throwing, [
            'kept-broken' => 'value',
            'kept-fine'   => 'value',
        ]);

        $this->assertSame([200, 200], array_map(fn ($result) => $result->getStatusCode(), $this->workerResults));
        $this->assertArrayNotHasKey('kept-broken', $seen);
        $this->assertSame('value', $seen['kept-fine'] ?? null);

        /*
         * The failure is logged. Checked outside the worker, because Octane's own listeners call
         * the logger and a spy cannot stand in for it there.
         */
        $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
        $store->putInMemoryCache('kept-broken', 'value');

        \Illuminate\Support\Facades\Log::spy();

        (new \ReflectionMethod(ResetsRequestState::class, 'flushMemoryRepository'))
            ->invoke(new ResetsRequestState(), $store, ['Winter.Tests.KeepValues' => $throwing]);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
        $this->assertNull($store->getFromMemoryCache('kept-broken'));
    }

    /**
     * The reset on the worker-error path keeps nothing, because the failed operation may have
     * written only part of a set of related values.
     *
     * @return void
     */
    public function testNothingIsKeptOnTheWorkerErrorPath(): void
    {
        $app = $this->bootWorker();
        $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
        $store->putInMemoryCache('kept-scalar', 'value');
        $store->putInMemoryCache('yaml::/error-path-1', ['a' => 1]);

        $this->withFakePlugins(['Winter.Tests.KeepPrefix' => new KeptCacheValuesPrefixPlugin($app)], function () use ($app) {
            (new ResetsRequestState())->handle(new WorkerErrorOccurred(new \RuntimeException('the original failure'), $app));
        });

        $this->assertNull($store->getFromMemoryCache('kept-scalar'));
        $this->assertNull($store->getFromMemoryCache('yaml::/error-path-1'));
    }

    /**
     * A parsed YAML value holding an object is flushed like any other, because every later request would
     * share the instance. Another key that merely looks similar is not kept either.
     *
     * @return void
     */
    public function testOnlyPlainParsedYamlIsKept(): void
    {
        $seen = $this->writeThenRead(new KeptCacheValuesPrefixPlugin($this->app), [
            'yaml::/plain-1'  => ['a' => 1],
            'yaml::/object-1' => new \ArrayObject(['user' => 'a']),
            'yaml::/nested-1' => ['x' => new \stdClass()],
            'yaml:/similar-1' => ['a' => 1],
        ]);

        $this->assertSame(['a' => 1], $seen['yaml::/plain-1'] ?? null);
        $this->assertArrayNotHasKey('yaml::/object-1', $seen);
        $this->assertArrayNotHasKey('yaml::/nested-1', $seen);
        $this->assertArrayNotHasKey('yaml:/similar-1', $seen);
    }

    /**
     * A parsed YAML value is inspected once, not on every reset, and a changed value under the same key
     * is inspected again.
     *
     * @return void
     */
    public function testParsedYamlIsInspectedOnce(): void
    {
        $reset = new class extends ResetsRequestState {
            public int $inspections = 0;

            /**
             * @param mixed $value
             * @return bool
             */
            protected function isPlainValue($value): bool
            {
                $this->inspections++;

                return parent::isPlainValue($value);
            }
        };

        $key = 'yaml::/' . bin2hex(random_bytes(6)) . '-1';
        $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
        $store->flushInternalCache();
        $store->putInMemoryCache($key, ['a' => 1]);

        $flush = new \ReflectionMethod(ResetsRequestState::class, 'flushMemoryRepository');
        $flush->invoke($reset, $store, [], true);
        $first = $reset->inspections;
        $flush->invoke($reset, $store, [], true);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $reset->inspections);

        $store->putInMemoryCache($key, new \stdClass());
        $flush->invoke($reset, $store, [], true);

        $this->assertNull($store->getFromMemoryCache($key));
    }

    /**
     * A later value that only compares equal to an inspected one may hold a reference, which every copy
     * shares, so the inspected copy stays in memory instead of it.
     *
     * @return void
     */
    public function testAnEqualParsedYamlValueHoldingAReferenceIsNotKept(): void
    {
        $key = 'yaml::/' . bin2hex(random_bytes(6)) . '-1';
        $seen = [];
        $shared = 1;

        $this->addWorkerRoute('_worker/yaml-prime', function () use ($key) {
            \Winter\Storm\Halcyon\Model::getCacheManager()->store('file')->putInMemoryCache($key, ['a' => 1]);

            return 'primed';
        });

        $this->addWorkerRoute('_worker/yaml-replace', function () use ($key, &$shared) {
            $value = ['a' => &$shared];
            \Winter\Storm\Halcyon\Model::getCacheManager()->store('file')->putInMemoryCache($key, $value);

            return 'replaced';
        });

        $this->addWorkerRoute('_worker/yaml-change', function () use ($key, &$shared, &$seen) {
            $shared = 'changed';
            $seen[] = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file')->getFromMemoryCache($key);

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/yaml-prime', 'GET'),
            Request::create('/_worker/yaml-replace', 'GET'),
            Request::create('/_worker/yaml-prime', 'GET'),
            Request::create('/_worker/yaml-replace', 'GET'),
            Request::create('/_worker/yaml-change', 'GET')
        );

        $this->assertSame([['a' => 1]], $seen);
    }

    /**
     * A plugin is asked whether it names the contract or only declares the method, and a plugin
     * offering neither is not asked.
     *
     * @return void
     */
    public function testAPluginKeepsValuesByContractOrByMethod(): void
    {
        $app = $this->bootWorker();

        /*
         * Built here, and only where the contract exists, for the reason PluginResetDiscoveryTest
         * gives: a file-scope class naming the interface would make this file unloadable without it.
         */
        $byContract = null;
        if (interface_exists(KeepsWorkerCacheValues::class)) {
            $byContract = new class ($app) extends PluginBase implements KeepsWorkerCacheValues
            {
                /**
                 * Keeps the key this plugin owns.
                 *
                 * @param string $key
                 * @param mixed $value
                 * @return bool
                 */
                public function keepWorkerCacheValue(string $key, $value): bool
                {
                    return $key === 'by-contract';
                }
            };
        }

        $fakes = [
            'Winter.Tests.KeepPrefix'  => new KeptCacheValuesPrefixPlugin($app),
            'Winter.Tests.KeepNeither' => new PluginResetDiscoveryInertPlugin($app),
        ];

        if ($byContract !== null) {
            $fakes['Winter.Tests.KeepByContract'] = $byContract;
        }

        $keepers = $this->withFakePlugins($fakes, function () {
            return (new \ReflectionMethod(ResetsRequestState::class, 'cacheValueKeepers'))
                ->invoke(new ResetsRequestState());
        });

        $expected = ['Winter.Tests.KeepPrefix'];
        if ($byContract !== null) {
            $expected[] = 'Winter.Tests.KeepByContract';
        }

        $this->assertSame($expected, array_keys($keepers));
    }

    /**
     * Puts the given values in the file store's memory layer in one request, then returns that
     * layer as the next request finds it, with the given plugin loaded.
     *
     * @param \System\Classes\PluginBase $plugin
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function writeThenRead(PluginBase $plugin, array $values): array
    {
        $seen = [];

        $this->addWorkerRoute('_worker/keep-set', function () use ($values) {
            $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');

            foreach ($values as $key => $value) {
                $store->putInMemoryCache($key, $value);
            }

            return 'set';
        });

        $this->addWorkerRoute('_worker/keep-read', function () use (&$seen) {
            $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
            $seen = (new \ReflectionProperty($store, 'cache'))->getValue($store);

            return 'read';
        });

        $this->withFakePlugins(['Winter.Tests.KeepValues' => $plugin], function () {
            $this->dispatchWorkerRequests(
                Request::create('/_worker/keep-set', 'GET'),
                Request::create('/_worker/keep-read', 'GET')
            );
        }, true);

        return $seen;
    }
}

/**
 * Keeps every key that starts with "kept-", by declaring the method alone.
 */
class KeptCacheValuesPrefixPlugin extends PluginBase
{
    /**
     * Keeps every key that starts with "kept-".
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function keepWorkerCacheValue(string $key, $value): bool
    {
        return str_starts_with($key, 'kept-');
    }
}
