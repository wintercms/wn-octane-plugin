<?php

namespace Winter\Octane\Tests;

use Cms\Classes\AutoDatasource;
use Cms\Classes\Page;
use Cms\Classes\Theme;
use Illuminate\Http\Request;
use Winter\Octane\Classes\WorkerFileDatasource;
use Winter\Storm\Filesystem\Filesystem;
use Winter\Storm\Halcyon\Datasource\FileDatasource;

/**
 * A worker reads theme templates through WorkerFileDatasource, and sees every change to a template
 * file on the next read, as PHP-FPM does.
 */
class WorkerFileDatasourceTest extends PersistentWorkerTestCase
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
     * Creates an empty theme with a pages directory.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('cms.databaseTemplates', false);

        $this->themeName = 'octane-test-' . bin2hex(random_bytes(6));
        $this->themePath = themes_path($this->themeName);

        mkdir($this->themePath . '/pages', 0777, true);
        file_put_contents($this->themePath . '/theme.yaml', "name: Octane test\n");
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
     * A theme registered before the worker started and one registered during a request both read
     * through the worker datasource, and later requests keep the same instance.
     *
     * @return void
     */
    public function testAWorkerReadsThemesThroughTheWorkerDatasource(): void
    {
        $resolver = $this->app->make('halcyon');
        $resolver->addDatasource('octane-before-boot', new FileDatasource($this->themePath, new Filesystem()));

        $this->bootWorker();

        $this->assertInstanceOf(WorkerFileDatasource::class, $resolver->datasource('octane-before-boot'));

        $seen = [];

        $this->addWorkerRoute('_worker/theme-datasource', function () use (&$seen) {
            $seen[] = Theme::load($this->themeName)->getDatasource();

            return 'read';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/theme-datasource', 'GET'),
            Request::create('/_worker/theme-datasource', 'GET')
        );

        $this->assertInstanceOf(WorkerFileDatasource::class, $seen[0] ?? null);
        $this->assertSame($seen[0], $seen[1] ?? null);
    }

    /**
     * Only a datasource that is exactly a FileDatasource is replaced, so a subclass and the
     * AutoDatasource that core builds for a child theme keep their own behavior.
     *
     * @return void
     */
    public function testOtherDatasourcesAreLeftAlone(): void
    {
        $resolver = $this->app->make('halcyon');
        $files = new Filesystem();
        $subclass = new class ($this->themePath, $files) extends FileDatasource {
        };
        $auto = new AutoDatasource(['filesystem' => new FileDatasource($this->themePath, $files)], 'octane-test-auto');

        $resolver->addDatasource('octane-subclass', $subclass);
        $resolver->addDatasource('octane-auto', $auto);

        $this->bootWorker();

        $this->assertSame($subclass, $resolver->datasource('octane-subclass'));
        $this->assertSame($auto, $resolver->datasource('octane-auto'));
    }

    /**
     * Outside a worker WorkerStarting never fires, so the plugin leaves core's datasource in place.
     *
     * @return void
     */
    public function testThemesKeepCoreDatasourceWithoutAWorker(): void
    {
        $this->assertSame(FileDatasource::class, get_class(Theme::load($this->themeName)->getDatasource()));
    }

    /**
     * A failure while preparing the worker is logged and does not stop it from booting.
     *
     * @return void
     */
    public function testAFailureWhilePreparingTheWorkerDoesNotStopIt(): void
    {
        $this->app->bind('halcyon', fn () => throw new \RuntimeException('no resolver'));

        $this->assertNotNull($this->bootWorker());
        $this->assertSame('ok', $this->dispatchFirstResponse());
    }

    /**
     * A failure while replacing a theme's datasource during a request leaves that theme on core's
     * datasource, and the request succeeds.
     *
     * @return void
     */
    public function testAFailureForALaterThemeDoesNotFailTheRequest(): void
    {
        $this->bootWorker();

        $warm = new class extends \Winter\Octane\Classes\WarmsWorker {
            /**
             * @param \Winter\Storm\Halcyon\Datasource\Resolver $resolver
             * @param string $name
             * @param object $datasource
             * @return void
             */
            protected function replaceDatasource(\Winter\Storm\Halcyon\Datasource\Resolver $resolver, string $name, object $datasource): void
            {
                throw new \RuntimeException('cannot replace');
            }
        };

        // The worker's own listener replaces the datasource first, so this only proves the guard when it runs alone.
        $this->app->make('events')->forget('cms.theme.registerHalcyonDatasource');
        $warm->handle((object) ['app' => $this->app]);

        $seen = null;

        $this->addWorkerRoute('_worker/later-theme', function () use (&$seen) {
            $seen = get_class(Theme::load($this->themeName)->getDatasource());

            return 'read';
        });

        $response = $this->dispatchWorkerRequest('/_worker/later-theme');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(FileDatasource::class, $seen);
    }

    /**
     * A kept template is read from disk once across requests, through the cached load the CMS router
     * uses, and an edit or a deletion still reaches the next request in the same worker.
     *
     * @return void
     */
    public function testChangesToAKeptTemplateReachTheNextRequest(): void
    {
        $file = $this->themePath . '/pages/changing.htm';
        file_put_contents($file, "url = \"/changing\"\n==\nfirst");
        touch($file, time() - 100);

        $files = $this->countingFilesystem();
        $this->app->make('halcyon')->addDatasource($this->themeName, $this->datasource($files));

        $seen = [];

        $this->addWorkerRoute('_worker/read-page', function () use (&$seen) {
            $page = Page::loadCached(Theme::load($this->themeName), 'changing');
            $seen[] = $page === null ? null : trim((string) $page->markup);

            return 'read';
        });

        $request = fn () => $this->dispatchWorkerRequests(Request::create('/_worker/read-page', 'GET'));

        $request();
        $request();

        $this->assertSame(['first', 'first'], $seen);
        $this->assertSame(1, $files->reads);

        file_put_contents($file, "url = \"/changing\"\n==\nsecond version");
        touch($file, time() - 50);
        $request();

        unlink($file);
        $request();

        $this->assertSame(['first', 'first', 'second version', null], $seen);
    }

    /**
     * A new override appears on the next read in the same worker, although the miss was remembered.
     *
     * @return void
     */
    public function testANewOverrideIsSeenAfterAMiss(): void
    {
        $datasource = $this->datasource(new Filesystem());

        $this->assertNull($datasource->selectOne('partials', 'new', 'htm'));

        mkdir($this->themePath . '/partials');
        file_put_contents($this->themePath . '/partials/new.htm', 'override');

        $this->assertSame('override', $datasource->selectOne('partials', 'new', 'htm')['content'] ?? null);
    }

    /**
     * A settled file is read once however many lookups it gets, and its contents are returned each
     * time. A missing file is not read at all.
     *
     * @return void
     */
    public function testASettledFileIsReadOnce(): void
    {
        $file = $this->themePath . '/pages/a.htm';
        file_put_contents($file, 'contents');

        $files = $this->countingFilesystem();
        $datasource = $this->datasource($files);

        $first = $datasource->selectOne('pages', 'a', 'htm');
        $second = $datasource->selectOne('pages', 'a', 'htm');

        $this->assertSame('contents', $first['content'] ?? null);
        $this->assertSame('a.htm', $first['fileName'] ?? null);
        $this->assertSame(filemtime($file), $first['mtime'] ?? null);
        $this->assertSame($first, $second);
        $this->assertSame(1, $files->reads);

        $this->assertNull($datasource->selectOne('pages', 'missing', 'htm'));
        $this->assertNull($datasource->lastModified('pages', 'missing', 'htm'));
        $this->assertSame(filemtime($file), $datasource->lastModified('pages', 'a', 'htm'));
        $this->assertSame(1, $files->reads);
    }

    /**
     * The same name in another directory or with another extension is a different template.
     *
     * @return void
     */
    public function testTheDirectoryAndExtensionSeparateTemplates(): void
    {
        mkdir($this->themePath . '/partials');
        file_put_contents($this->themePath . '/pages/a.htm', 'page');
        file_put_contents($this->themePath . '/partials/a.htm', 'partial');
        file_put_contents($this->themePath . '/pages/a.md', 'markdown');

        $datasource = $this->datasource(new Filesystem());

        $this->assertSame('page', $datasource->selectOne('pages', 'a', 'htm')['content'] ?? null);
        $this->assertSame('partial', $datasource->selectOne('partials', 'a', 'htm')['content'] ?? null);
        $this->assertSame('markdown', $datasource->selectOne('pages', 'a', 'md')['content'] ?? null);
    }

    /**
     * A write that lands after the read is not hidden by the stat taken before it.
     *
     * @return void
     */
    public function testAWriteDuringTheReadIsSeenNextTime(): void
    {
        file_put_contents($this->themePath . '/pages/a.htm', 'old');

        $files = new class extends Filesystem {
            public bool $written = false;

            /**
             * @param string $path
             * @param bool $lock
             * @return string
             */
            public function get($path, $lock = false)
            {
                $content = parent::get($path, $lock);

                if (!$this->written) {
                    $this->written = true;
                    file_put_contents($path, 'new content');
                }

                return $content;
            }
        };

        $datasource = $this->datasource($files);

        $this->assertSame('old', $datasource->selectOne('pages', 'a', 'htm')['content'] ?? null);
        $this->assertSame('new content', $datasource->selectOne('pages', 'a', 'htm')['content'] ?? null);
    }

    /**
     * A file changed recently could change again with the same timestamps, so it is read again until
     * it settles. An old modification time does not count when the change time is recent.
     *
     * @return void
     */
    public function testARecentlyChangedFileIsNotKept(): void
    {
        file_put_contents($this->themePath . '/pages/a.htm', 'contents');

        $files = $this->countingFilesystem();
        touch($this->themePath . '/pages/a.htm', time() - 1000);

        $datasource = new class ($this->themePath, $files) extends WorkerFileDatasource {
            protected const SETTLE_SECONDS = 500;
        };

        $datasource->selectOne('pages', 'a', 'htm');
        $datasource->selectOne('pages', 'a', 'htm');

        $this->assertSame(2, $files->reads);
    }

    /**
     * An edit that restores the modification time and keeps the size still moves the file's change
     * time, so it is not mistaken for the kept contents.
     *
     * @return void
     */
    public function testAnEditThatKeepsTheModificationTimeAndSizeIsSeen(): void
    {
        $file = $this->themePath . '/pages/a.htm';
        file_put_contents($file, 'aaaa');
        $mtime = filemtime($file);

        // Waits out the settling time so the contents are really kept before the edit.
        sleep(3);

        $files = $this->countingFilesystem();
        $datasource = WorkerFileDatasource::fromFileDatasource(new FileDatasource($this->themePath, $files));

        $this->assertSame('aaaa', $datasource->selectOne('pages', 'a', 'htm')['content'] ?? null);
        $this->assertSame('aaaa', $datasource->selectOne('pages', 'a', 'htm')['content'] ?? null);
        $this->assertSame(1, $files->reads);

        file_put_contents($file, 'bbbb');
        touch($file, $mtime);
        clearstatcache();

        $this->assertSame($mtime, filemtime($file));
        $this->assertSame('bbbb', $datasource->selectOne('pages', 'a', 'htm')['content'] ?? null);
    }

    /**
     * A path outside the datasource is refused on every read, not only the first.
     *
     * @return void
     */
    public function testAPathOutsideTheDatasourceIsRefused(): void
    {
        file_put_contents($this->themePath . '/secret.htm', 'secret');

        $datasource = $this->datasource(new Filesystem());

        $this->assertNull($datasource->selectOne('pages', '../secret', 'htm'));
        $this->assertNull($datasource->selectOne('pages', '../secret', 'htm'));
        $this->assertNull($datasource->lastModified('pages', '../secret', 'htm'));
    }

    /**
     * A template replaced by a symlink to a file outside the theme is refused, as FileDatasource refuses
     * it, whether it was read before or was a remembered miss. Removing the symlink is seen too.
     *
     * @return void
     */
    public function testASymlinkToAFileOutsideIsRefusedAfterThePathWasRemembered(): void
    {
        $outside = $this->themePath . '-outside.txt';
        file_put_contents($outside, 'SECRET');
        file_put_contents($this->themePath . '/pages/read.htm', 'inside');

        $datasource = $this->datasource(new Filesystem());

        try {
            $this->assertSame('inside', $datasource->selectOne('pages', 'read', 'htm')['content'] ?? null);
            $this->assertNull($datasource->selectOne('pages', 'missed', 'htm'));

            unlink($this->themePath . '/pages/read.htm');
            symlink($outside, $this->themePath . '/pages/read.htm');
            symlink($outside, $this->themePath . '/pages/missed.htm');

            $this->assertNull($datasource->selectOne('pages', 'read', 'htm'));
            $this->assertNull($datasource->selectOne('pages', 'missed', 'htm'));
            $this->assertNull($datasource->lastModified('pages', 'read', 'htm'));

            unlink($this->themePath . '/pages/read.htm');
            file_put_contents($this->themePath . '/pages/read.htm', 'back inside');

            $this->assertSame('back inside', $datasource->selectOne('pages', 'read', 'htm')['content'] ?? null);
        }
        finally {
            @unlink($outside);
        }
    }

    /**
     * A directory replaced by a symlink to a directory outside the theme is refused, for a remembered hit
     * and a remembered miss, as a FileDatasource that has already read from that directory refuses it.
     *
     * @return void
     */
    public function testASymlinkedDirectoryOutsideIsRefusedAfterThePathWasRemembered(): void
    {
        $outside = $this->themePath . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/hit.htm', 'SECRET');
        file_put_contents($outside . '/miss.htm', 'SECRET');

        mkdir($this->themePath . '/partials');
        file_put_contents($this->themePath . '/partials/hit.htm', 'inside');

        $datasource = $this->datasource(new Filesystem());

        try {
            $this->assertSame('inside', $datasource->selectOne('partials', 'hit', 'htm')['content'] ?? null);
            $this->assertNull($datasource->selectOne('partials', 'miss', 'htm'));

            (new Filesystem())->deleteDirectory($this->themePath . '/partials');
            symlink($outside, $this->themePath . '/partials');

            $this->assertNull($datasource->selectOne('partials', 'hit', 'htm'));
            $this->assertNull($datasource->selectOne('partials', 'miss', 'htm'));
            $this->assertNull($datasource->lastModified('partials', 'hit', 'htm'));
        }
        finally {
            @unlink($this->themePath . '/partials');
            (new Filesystem())->deleteDirectory($outside);
        }
    }

    /**
     * A subdirectory nested below the first segment is checked too.
     *
     * @return void
     */
    public function testANestedSymlinkedDirectoryOutsideIsRefused(): void
    {
        $outside = $this->themePath . '-nested-outside';
        mkdir($outside);
        file_put_contents($outside . '/a.htm', 'SECRET');

        mkdir($this->themePath . '/partials/sub', 0777, true);
        file_put_contents($this->themePath . '/partials/sub/a.htm', 'inside');

        $datasource = $this->datasource(new Filesystem());

        try {
            $this->assertSame('inside', $datasource->selectOne('partials', 'sub/a', 'htm')['content'] ?? null);

            (new Filesystem())->deleteDirectory($this->themePath . '/partials/sub');
            symlink($outside, $this->themePath . '/partials/sub');

            $this->assertNull($datasource->selectOne('partials', 'sub/a', 'htm'));
        }
        finally {
            @unlink($this->themePath . '/partials/sub');
            (new Filesystem())->deleteDirectory($outside);
        }
    }

    /**
     * A template that is a symlink inside the theme reads like FileDatasource reads it, and stops when
     * the symlink is removed.
     *
     * @return void
     */
    public function testASymlinkInsideTheThemeIsFollowedUntilRemoved(): void
    {
        file_put_contents($this->themePath . '/pages/real.htm', 'real');
        symlink($this->themePath . '/pages/real.htm', $this->themePath . '/pages/alias.htm');

        $datasource = $this->datasource(new Filesystem());

        $this->assertSame('real', $datasource->selectOne('pages', 'alias', 'htm')['content'] ?? null);

        unlink($this->themePath . '/pages/alias.htm');

        $this->assertNull($datasource->selectOne('pages', 'alias', 'htm'));
    }

    /**
     * Writes and list queries still work through the parent class, and a write is seen by the next read.
     *
     * @return void
     */
    public function testWritesAndListsUseTheParentClass(): void
    {
        $datasource = WorkerFileDatasource::fromFileDatasource(new FileDatasource($this->themePath, new Filesystem()));

        $datasource->insert('pages', 'new', 'htm', 'created');
        $this->assertSame('created', $datasource->selectOne('pages', 'new', 'htm')['content'] ?? null);
        $this->assertSame(['new.htm'], array_column($datasource->select('pages'), 'fileName'));

        $datasource->update('pages', 'new', 'htm', 'updated and longer');
        $this->assertSame('updated and longer', $datasource->selectOne('pages', 'new', 'htm')['content'] ?? null);

        $datasource->delete('pages', 'new', 'htm');
        $this->assertNull($datasource->selectOne('pages', 'new', 'htm'));
    }

    /**
     * The contents kept and the paths remembered both stay within their limits, and a file too large
     * to keep is still returned.
     *
     * @return void
     */
    public function testKeptStateStaysWithinItsLimits(): void
    {
        file_put_contents($this->themePath . '/pages/a.htm', str_repeat('a', 6));
        file_put_contents($this->themePath . '/pages/b.htm', str_repeat('b', 6));
        file_put_contents($this->themePath . '/pages/huge.htm', str_repeat('c', 11));

        $datasource = new class ($this->themePath, new Filesystem()) extends WorkerFileDatasource {
            protected const MAX_CONTENT_BYTES = 10;
            protected const MAX_READ_PATHS = 2;
            protected const SETTLE_SECONDS = -3600;

            /**
             * @return array{paths: int, contents: int, bytes: int}
             */
            public function sizes(): array
            {
                return [
                    'paths'    => count($this->readPaths),
                    'contents' => count($this->contents),
                    'bytes'    => $this->contentBytes,
                ];
            }
        };

        $datasource->selectOne('pages', 'a', 'htm');
        $this->assertSame(['paths' => 1, 'contents' => 1, 'bytes' => 6], $datasource->sizes());

        $datasource->selectOne('pages', 'b', 'htm');
        $this->assertSame(['paths' => 2, 'contents' => 1, 'bytes' => 6], $datasource->sizes());

        $this->assertSame(str_repeat('c', 11), $datasource->selectOne('pages', 'huge', 'htm')['content'] ?? null);
        $this->assertSame(['paths' => 1, 'contents' => 1, 'bytes' => 6], $datasource->sizes());

        // An edit replaces the kept size, and a deletion releases it.
        file_put_contents($this->themePath . '/pages/b.htm', 'bb');
        $datasource->selectOne('pages', 'b', 'htm');
        $this->assertSame(2, $datasource->sizes()['bytes']);

        unlink($this->themePath . '/pages/b.htm');
        $datasource->selectOne('pages', 'b', 'htm');
        $this->assertSame(['contents' => 0, 'bytes' => 0], array_intersect_key($datasource->sizes(), ['contents' => 1, 'bytes' => 1]));
    }

    /**
     * Copying a datasource keeps its settings.
     *
     * @return void
     */
    public function testCopyingADatasourceKeepsItsSettings(): void
    {
        $source = new FileDatasource($this->themePath, new Filesystem());
        (new \ReflectionProperty(FileDatasource::class, 'maxDepth'))->setValue($source, 3);

        $copy = WorkerFileDatasource::fromFileDatasource($source);

        $this->assertSame($this->themePath, $copy->getBasePath());
        $this->assertSame(3, (new \ReflectionProperty(FileDatasource::class, 'maxDepth'))->getValue($copy));
        $this->assertSame($source->getPathsCacheKey(), $copy->getPathsCacheKey());
    }

    /**
     * Dispatch one request to a route that returns "ok", and return the response body.
     *
     * @return string
     */
    protected function dispatchFirstResponse(): string
    {
        $this->addWorkerRoute('_worker/warm-ok', fn () => 'ok');

        return (string) $this->dispatchWorkerRequest('/_worker/warm-ok')->getContent();
    }

    /**
     * Make a datasource over the temporary theme that keeps contents straight away.
     *
     * @param \Winter\Storm\Filesystem\Filesystem $files
     * @return \Winter\Octane\Classes\WorkerFileDatasource
     */
    protected function datasource(Filesystem $files): WorkerFileDatasource
    {
        return new class ($this->themePath, $files) extends WorkerFileDatasource {
            protected const SETTLE_SECONDS = -3600;
        };
    }

    /**
     * Make a filesystem that counts the files it reads.
     *
     * @return \Winter\Storm\Filesystem\Filesystem
     */
    protected function countingFilesystem(): Filesystem
    {
        return new class extends Filesystem {
            public int $reads = 0;

            /**
             * @param string $path
             * @param bool $lock
             * @return string
             */
            public function get($path, $lock = false)
            {
                $this->reads++;

                return parent::get($path, $lock);
            }
        };
    }
}
