<?php

namespace Winter\Octane\Tests;

use Cms\Classes\Page;
use Cms\Classes\Partial;
use Cms\Classes\Theme;
use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Request;
use Winter\Octane\Classes\ResetsRequestState;
use Winter\Octane\Classes\WorkerFileDatasource;
use Winter\Storm\Filesystem\Filesystem;
use Winter\Storm\Halcyon\MemoryRepository;

/**
 * A worker keeps the templates Halcyon parsed through WorkerFileDatasource in memory, and still sees
 * every change to a template file and every emptying of the cache store on the next request.
 */
class KeptTemplatesTest extends PersistentWorkerTestCase
{
    /**
     * @var string A temporary theme's name, under the installation's themes directory.
     */
    protected string $themeName = '';

    /**
     * @var string The temporary theme's directory.
     */
    protected string $themePath = '';

    /**
     * Creates an empty theme read through the worker datasource, with the file cache as the default
     * store, because Octane already flushes the array store between operations.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('cms.databaseTemplates', false);
        $this->app['config']->set('cache.default', 'file');

        $this->themeName = 'octane-test-' . bin2hex(random_bytes(6));
        $this->themePath = themes_path($this->themeName);

        mkdir($this->themePath . '/pages', 0777, true);
        mkdir($this->themePath . '/partials', 0777, true);
        file_put_contents($this->themePath . '/theme.yaml', "name: Octane test\n");

        \Winter\Storm\Halcyon\Model::getCacheManager()->store('file')->flushInternalCache();

        $this->app->make('halcyon')->addDatasource(
            $this->themeName,
            new WorkerFileDatasource($this->themePath, new Filesystem())
        );
    }

    /**
     * Removes the temporary theme.
     *
     * @return void
     */
    public function tearDown(): void
    {
        if ($this->themePath !== '') {
            (new Filesystem())->deleteDirectory($this->themePath);
        }

        parent::tearDown();
    }

    /**
     * A parsed page stays in memory from the request after the store's generation is first seen, and
     * an edit to the file reaches the next request.
     *
     * @return void
     */
    public function testAParsedPageIsKeptUntilTheFileChanges(): void
    {
        $name = 'kept-' . bin2hex(random_bytes(4));
        $file = $this->themePath . '/pages/' . $name . '.htm';
        file_put_contents($file, "url = \"/{$name}\"\n==\nfirst");
        touch($file, time() - 100);

        $seen = $this->readRepeatedly(fn () => Page::loadCached(Theme::load($this->themeName), $name), 3);

        file_put_contents($file, "url = \"/{$name}\"\n==\nsecond version");
        touch($file, time() - 50);

        $seen = array_merge($seen, $this->readRepeatedly(fn () => Page::loadCached(Theme::load($this->themeName), $name), 2));

        $this->assertSame([
            ['in memory' => false, 'markup' => 'first'],
            ['in memory' => false, 'markup' => 'first'],
            ['in memory' => true, 'markup' => 'first'],
            ['in memory' => true, 'markup' => 'second version'],
            ['in memory' => true, 'markup' => 'second version'],
        ], $seen);
    }

    /**
     * A missing override is kept as a miss, and a file created for it is found on the next request.
     *
     * @return void
     */
    public function testAKeptMissSeesANewFile(): void
    {
        $name = 'override-' . bin2hex(random_bytes(4));

        $seen = $this->readRepeatedly(fn () => Partial::loadCached(Theme::load($this->themeName), $name), 3);

        file_put_contents($this->themePath . '/partials/' . $name . '.htm', 'override');

        $seen = array_merge($seen, $this->readRepeatedly(fn () => Partial::loadCached(Theme::load($this->themeName), $name), 1));

        $this->assertSame([
            ['in memory' => false, 'markup' => null],
            ['in memory' => false, 'markup' => null],
            ['in memory' => true, 'markup' => null],
            ['in memory' => true, 'markup' => 'override'],
        ], $seen);
    }

    /**
     * Emptying the external store, as `cache:clear` does from any process, drops the kept templates
     * from the worker's memory at the next request, and the request after that keeps them again.
     *
     * @return void
     */
    public function testEmptyingTheStoreDropsKeptTemplates(): void
    {
        $name = 'flushed-' . bin2hex(random_bytes(4));
        $file = $this->themePath . '/pages/' . $name . '.htm';
        file_put_contents($file, "url = \"/{$name}\"\n==\nkept");
        touch($file, time() - 100);

        $read = fn () => Page::loadCached(Theme::load($this->themeName), $name);
        $seen = $this->readRepeatedly($read, 3);

        \Winter\Storm\Halcyon\Model::getCacheManager()->store('file')->getStore()->flush();

        $seen = array_merge($seen, $this->readRepeatedly($read, 3));

        $this->assertSame([false, false, true, false, true, true], array_column($seen, 'in memory'));
    }

    /**
     * Only a value with the shape of a single-template result is kept, and only under a key a worker
     * datasource made for one theme directory.
     *
     * @return void
     */
    public function testOnlyTemplateResultsUnderAWorkerDatasourceKeyAreKept(): void
    {
        $other = new WorkerFileDatasource($this->themePath . '/partials', new Filesystem());
        $datasource = new WorkerFileDatasource($this->themePath, new Filesystem());
        $single = fn (string $name) => serialize([$name, 'htm']) . '-*-*--' . '-';

        $record = ['a.htm' => ['fileName' => 'a.htm', 'content' => 'x', 'mtime' => 1, 'markup' => 'x']];
        $values = [
            'pages' . $datasource->makeCacheKey($single('shape-record'))  => $record,
            'pages' . $datasource->makeCacheKey($single('shape-miss'))    => [],
            'pages' . $datasource->makeCacheKey($single('shape-object'))  => ['a.htm' => new \ArrayObject()],
            'pages' . $datasource->makeCacheKey($single('shape-renamed')) => ['b.htm' => $record['a.htm']],
            'pages' . $datasource->makeCacheKey('*-*-*--' . bin2hex(random_bytes(4))) => $record,
            'pages' . bin2hex(random_bytes(4))                            => $record,
        ];

        $shared = 'pages' . $datasource->makeCacheKey($single('shape-shared'));
        $other->makeCacheKey($single('shape-shared'));
        $values[$shared] = $record;

        $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
        $this->flushTwice($store, $values);

        $kept = array_keys((new \ReflectionProperty($store, 'cache'))->getValue($store));
        $keys = array_keys($values);

        $this->assertSame([$keys[0], $keys[1]], $kept);
    }

    /**
     * A store that cannot be read keeps no template, and the reset carries on.
     *
     * @return void
     */
    public function testAStoreThatCannotBeReadKeepsNoTemplate(): void
    {
        $datasource = new WorkerFileDatasource($this->themePath, new Filesystem());
        $key = 'pages' . $datasource->makeCacheKey(serialize(['unreadable', 'htm']) . '-*-*--' . '-');

        $store = new MemoryRepository(new class extends ArrayStore {
            /**
             * @param string $key
             * @return mixed
             */
            public function get($key)
            {
                throw new \RuntimeException('the store is down');
            }
        });

        $this->flushTwice($store, [$key => []]);

        $this->assertSame([], (new \ReflectionProperty($store, 'cache'))->getValue($store));
    }

    /**
     * Puts values in a store's memory layer and runs the reset's flush over it twice, so the second
     * flush runs with the generation the first one saw.
     *
     * @param \Winter\Storm\Halcyon\MemoryRepository $store
     * @param array<string, mixed> $values
     * @return void
     */
    protected function flushTwice(MemoryRepository $store, array $values): void
    {
        $flush = new \ReflectionMethod(ResetsRequestState::class, 'flushMemoryRepository');

        foreach ([1, 2] as $pass) {
            $store->flushInternalCache();

            foreach ($values as $key => $value) {
                $store->putInMemoryCache($key, $value);
            }

            $flush->invoke(new ResetsRequestState(), $store, [], true);
        }
    }

    /**
     * Load a template in several worker requests, and say for each whether a parsed template was in
     * memory when the request began, and the markup it loaded.
     *
     * @param callable(): (\Cms\Classes\CmsCompoundObject|null) $load
     * @param int $times
     * @return list<array{in memory: bool, markup: string|null}>
     */
    protected function readRepeatedly(callable $load, int $times): array
    {
        $seen = [];
        $uri = '_worker/kept-template-' . bin2hex(random_bytes(4));

        $this->addWorkerRoute($uri, function () use ($load, &$seen) {
            $store = \Winter\Storm\Halcyon\Model::getCacheManager()->store('file');
            $keys = array_keys((new \ReflectionProperty($store, 'cache'))->getValue($store));
            $template = $load();

            $seen[] = [
                'in memory' => array_filter($keys, [WorkerFileDatasource::class, 'madeRecordCacheKey']) !== [],
                'markup'    => $template === null ? null : trim((string) $template->markup),
            ];

            return 'read';
        });

        $this->dispatchWorkerRequests(...array_map(
            fn () => Request::create('/' . $uri, 'GET'),
            range(1, $times)
        ));

        return $seen;
    }
}
