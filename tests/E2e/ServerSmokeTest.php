<?php

namespace Winter\Octane\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;

/**
 * Serves the surrounding Winter installation through a REAL Octane server and checks that state
 * planted by one HTTP request is gone by the next, and that the server only serves Winter's
 * whitelisted files.
 *
 * It plants state through a real HTTP request (a Twig include gate, a shared view variable, a CMS
 * code-parser cache entry), and reads it back through a second request served by the same worker
 * process. A leak here is a leak in production. It runs against the server WINTER_OCTANE_E2E_SERVER
 * names; see OctaneServerTestCase.
 */
#[Group('e2e')]
class ServerSmokeTest extends OctaneServerTestCase
{
    /**
     * State planted by one HTTP request is gone when the same worker process serves the next one.
     *
     * @return void
     */
    public function testStatePlantedByOneRequestIsGoneByTheNext(): void
    {
        $this->startServer();

        $baseline = $this->get('/_octane-smoke/read');

        $this->assertFalse($baseline['twig_gate_open'], 'the include gate should start closed');
        $this->assertFalse($baseline['view_leak_present'], 'no smoke share should exist yet');
        $this->assertFalse($baseline['code_parser_dirty'], 'the parser cache should start clean');

        $planted = $this->get('/_octane-smoke/plant');
        $this->assertTrue($planted['planted']);

        $after = $this->get('/_octane-smoke/read');

        $this->assertSame(
            $baseline['pid'],
            $after['pid'],
            'the follow-up request was served by a different worker process, so this run proves '
            . 'nothing about cross-request state; with --workers=1 that means the worker crashed'
        );

        $this->assertFalse(
            $after['twig_gate_open'],
            'the Twig include gate planted by the previous request survived into this one'
        );
        $this->assertFalse(
            $after['view_leak_present'],
            'the view share planted by the previous request survived into this one'
        );
        $this->assertFalse(
            $after['code_parser_dirty'],
            'the code parser cache planted by the previous request survived into this one'
        );
    }

    /**
     * Winter serves from the project root, so the server must only serve Winter's whitelisted asset
     * paths as files. Octane's own configuration for each server serves any file in the project, and
     * FrankenPHP's also ran any PHP file in it.
     *
     * @return void
     */
    public function testOnlyWhitelistedFilesAreServed(): void
    {
        $this->startServer();

        /*
         * A file the application writes and nothing should serve: a log, like the ones in
         * storage/logs. It is written by the test so the assertion does not depend on the site having
         * logged anything yet, and removed again afterwards.
         */
        $log = $this->winterRoot . '/storage/logs/octane-e2e-probe.log';
        file_put_contents($log, "OCTANE-E2E-LOG-MARKER\n");

        try {
            /*
             * A path that does not exist shows how the application answers. A sensitive path must get
             * the same status, which an executed PHP file (200 or 500) or a served file (200) would not.
             */
            [$notFoundStatus] = $this->fetch('/winter-octane-no-such-page');

            $this->assertSame(404, $notFoundStatus, 'a page that does not exist must answer 404');

            $leaks = [
                '/.env' => 'APP_KEY',
                '/composer.json' => '"require"',
                '/artisan' => 'Artisan',
                '/config/app.php' => 'Fatal error',
                '/storage/logs/octane-e2e-probe.log' => 'OCTANE-E2E-LOG-MARKER',
                '/plugins/winter/octane/Plugin.php' => 'Fatal error',
                '/modules/system/assets/../../../.env' => 'APP_KEY',
                '/modules/system/assets/%2e%2e/%2e%2e/%2e%2e/.env' => 'APP_KEY',
                '/modules/system/assets/..%2f..%2f..%2fcomposer.json' => '"require"',
                '/storage/app/media/../../../composer.json' => '"require"',
                '/modules/system/assets/../../../config/app.php' => 'Fatal error',
            ];

            foreach ($leaks as $path => $marker) {
                [$status, $body] = $this->fetchRaw($path);
                $message = "{$path} was served or executed directly by {$this->server} instead of going to the application";

                $this->assertSame($notFoundStatus, $status, $message);
                $this->assertStringNotContainsString($marker, (string) $body, $message);
            }
        }
        finally {
            @unlink($log);
        }

        [$status, $body] = $this->fetch('/modules/system/assets/js/framework.js');
        $this->assertSame(200, $status, 'module assets must still be served as files');
        $this->assertStringContainsString('function', (string) $body);
    }

    /**
     * A whitelisted file arrives whole through the real server, including for a range request and
     * without a body for HEAD.
     *
     * Under Swoole and RoadRunner the worker serves these files, and Swoole's client sends the whole
     * file whatever status and length the response claims. The worker therefore answers a range
     * request with a full 200, and a client that trusted a 206 with a partial length would read a
     * corrupt file. Caddy serves them itself under FrankenPHP, and answers a range request with the
     * range.
     *
     * @return void
     */
    public function testWhitelistedFilesAreDeliveredWhole(): void
    {
        $this->startServer();

        $path = '/modules/system/assets/js/framework.js';
        $size = filesize($this->winterRoot . $path);

        [$status, $body] = $this->fetchRaw($path, 'GET', ['Range: bytes=0-9']);

        if ($this->server === 'frankenphp') {
            $this->assertSame(206, $status, 'Caddy answers a range request with the range');
            $this->assertSame(10, strlen($body), 'Caddy must send exactly the requested range');
        }
        else {
            $this->assertSame(200, $status, "the worker must answer a range request with the whole file under {$this->server}");
            $this->assertSame($size, strlen($body), "the whole file must arrive under {$this->server}");
        }

        [$status, $body, $head] = $this->fetchRaw($path, 'HEAD');

        $this->assertSame(200, $status, 'HEAD on a whitelisted file must succeed');
        $this->assertSame('', $body, 'HEAD must not send a body');
        $this->assertMatchesRegularExpression('#^Content-Type:\s*\S*javascript#mi', $head, 'HEAD must send the content type');
    }
}
