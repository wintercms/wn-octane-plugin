<?php

namespace Winter\Octane\Tests;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Winter\Octane\Classes\ServePublicFiles;

/**
 * A worker serves the files Winter's .htaccess whitelists, and no other file in the project root.
 */
class ServePublicFilesTest extends PersistentWorkerTestCase
{
    /**
     * A whitelisted asset that ships with Winter, as a request path.
     */
    protected const ASSET = '/modules/system/assets/css/styles.css';

    /**
     * A log file created for the test, as a request path.
     */
    protected const LOG = '/storage/logs/winter-octane-public-files.log';

    /**
     * Files created for the test, as request paths: a log file, and PHP files in a whitelisted folder.
     */
    protected const CREATED = [
        self::LOG,
        '/storage/app/media/winter-octane-script.php',
        '/storage/app/media/winter-octane-script-upper.PHP',
    ];

    /**
     * Creates the test's files.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        foreach (static::CREATED as $path) {
            file_put_contents(base_path($path), '<?php // created by ServePublicFilesTest');
        }
    }

    /**
     * Removes the test's files.
     *
     * @return void
     */
    public function tearDown(): void
    {
        foreach (static::CREATED as $path) {
            @unlink(base_path($path));
        }

        parent::tearDown();
    }

    /**
     * Request paths that must not be served as files.
     *
     * @return array<string, array{string}>
     */
    public static function privatePaths(): array
    {
        return [
            'environment file' => ['/.env'],
            'composer metadata' => ['/composer.json'],
            'log file' => [self::LOG],
            'configuration' => ['/config/app.php'],
            'front controller' => ['/index.php'],
            'PHP file in a whitelisted folder' => ['/storage/app/media/winter-octane-script.php'],
            'PHP file with an upper-case extension' => ['/storage/app/media/winter-octane-script-upper.PHP'],
            'dot segments out of a whitelisted folder' => ['/modules/system/assets/../../../.env'],
            'encoded dot segments' => ['/modules/system/assets/%2e%2e/%2e%2e/%2e%2e/composer.json'],
            'encoded separator' => ['/modules/system/assets%2f..%2f..%2f..%2f.env'],
            'whitelisted folder that is not a file' => ['/modules/system/assets/css'],
        ];
    }

    /**
     * No private file resolves to a file to serve.
     *
     * @param string $path
     * @return void
     */
    #[DataProvider('privatePaths')]
    public function testAPrivatePathIsNotResolved(string $path): void
    {
        $this->assertNull(ServePublicFiles::resolve(base_path(), $path));
    }

    /**
     * Whitelisted files resolve to the file in the project root, including through dot segments that
     * stay inside a whitelisted folder.
     *
     * @return void
     */
    public function testAWhitelistedPathIsResolved(): void
    {
        $file = base_path() . static::ASSET;

        $this->assertSame($file, ServePublicFiles::resolve(base_path(), static::ASSET));
        $this->assertSame($file, ServePublicFiles::resolve(base_path(), '/modules/system/assets/js/../css/styles.css'));
        $this->assertSame($file, ServePublicFiles::resolve(base_path(), '//modules/system/assets/css/%73tyles.css'));
    }

    /**
     * The worker serves a whitelisted asset as a file with its content type, and passes every private
     * path to the application, which does not return the file.
     *
     * @return void
     */
    public function testTheWorkerServesOnlyWhitelistedFiles(): void
    {
        $private = array_values(array_filter(
            ['/.env', '/composer.json', static::LOG, '/config/app.php', '/index.php', '/storage/app/media/winter-octane-script.php'],
            fn ($path) => is_file(base_path($path))
        ));

        $results = $this->dispatchWorkerRequests(
            Request::create(static::ASSET),
            ...array_map(fn ($path) => Request::create($path), $private)
        );

        $asset = array_shift($results);

        $this->assertInstanceOf(BinaryFileResponse::class, $asset);
        $this->assertSame(200, $asset->getStatusCode());
        $this->assertStringStartsWith('text/css', (string) $asset->headers->get('Content-Type'));
        $this->assertSame(base_path() . static::ASSET, $asset->getFile()->getPathname());

        foreach ($results as $index => $result) {
            $this->assertNotInstanceOf(Throwable::class, $result);
            $this->assertNotInstanceOf(BinaryFileResponse::class, $result, $private[$index] . ' was served as a file.');
            $this->assertStringNotContainsString(
                (string) file_get_contents(base_path($private[$index])),
                $result instanceof Response ? (string) $result->getContent() : '',
                $private[$index] . ' was returned.'
            );
        }
    }

    /**
     * A Range request and a revalidation get the whole file with a 200, and a HEAD request gets the
     * headers with no file.
     *
     * Octane's Swoole client sends a file response from its offset to the end of the file whatever
     * the status says, so a 206 or a 304 would send the wrong bytes under that header.
     *
     * @return void
     */
    public function testRangeConditionalAndHeadRequestsGetTheWholeFileOrNoBody(): void
    {
        $range = Request::create(static::ASSET);
        $range->headers->set('Range', 'bytes=0-9');

        $revalidation = Request::create(static::ASSET);
        $revalidation->headers->set('If-Modified-Since', gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

        [$ranged, $revalidated, $head] = $this->dispatchWorkerRequests(
            $range,
            $revalidation,
            Request::create(static::ASSET, 'HEAD')
        );

        foreach (['Range' => $ranged, 'If-Modified-Since' => $revalidated] as $header => $result) {
            $this->assertInstanceOf(BinaryFileResponse::class, $result, $header);
            $this->assertSame(200, $result->getStatusCode(), $header);
            $this->assertSame((string) filesize(base_path() . static::ASSET), $result->headers->get('Content-Length'), $header);
            $this->assertNull($result->headers->get('Content-Range'), $header);
            $this->assertSame('none', $result->headers->get('Accept-Ranges'), $header);
        }

        $this->assertNotInstanceOf(BinaryFileResponse::class, $head);
        $this->assertInstanceOf(Response::class, $head);
        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame('', (string) $head->getContent());
        $this->assertStringStartsWith('text/css', (string) $head->headers->get('Content-Type'));
    }

    /**
     * Only GET and HEAD requests are served as files. A POST continues to the application.
     *
     * @return void
     */
    public function testAPostToAWhitelistedFileIsNotServed(): void
    {
        [$result] = $this->dispatchWorkerRequests(Request::create(static::ASSET, 'POST'));

        $this->assertNotInstanceOf(BinaryFileResponse::class, $result);
    }

    /**
     * The plugin's Caddyfile serves the same paths as the worker.
     *
     * @return void
     */
    public function testTheCaddyfileUsesTheSameWhitelist(): void
    {
        $this->assertStringContainsString(
            'path_regexp ' . ServePublicFiles::WHITELIST . "\n",
            (string) file_get_contents(dirname(__DIR__) . '/caddy/Caddyfile')
        );
    }
}
