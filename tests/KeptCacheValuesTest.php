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

        $this->withFakePlugins(['Winter.Tests.KeepPrefix' => new KeptCacheValuesPrefixPlugin($app)], function () use ($app) {
            (new ResetsRequestState())->handle(new WorkerErrorOccurred(new \RuntimeException('the original failure'), $app));
        });

        $this->assertNull($store->getFromMemoryCache('kept-scalar'));
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
