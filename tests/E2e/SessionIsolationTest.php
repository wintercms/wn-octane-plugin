<?php

namespace Winter\Octane\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;

/**
 * Two browsers on one worker: one signs a back-end user in through the real sign-in form, the other
 * stays anonymous, and neither gets the other's session.
 *
 * SessionDriftTest proves the same in-process. This runs it over HTTP against a real server, so the
 * cookies are the ones the server's own HTTP layer (Caddy, Swoole or RoadRunner) parses and writes,
 * the session is the site's configured session store, and the sign-in goes through the back-end's
 * sign-in form with its CSRF token. Every request reaches the one worker, which the process IDs
 * confirm, so anything the signed-in request leaves in memory is there when the anonymous request
 * arrives.
 */
#[Group('e2e')]
class SessionIsolationTest extends OctaneServerTestCase
{
    /**
     * The back-end user the test signs in. Created on first run and kept, with a new password each
     * run, since the console has no command to delete a user again.
     */
    protected const LOGIN = 'octane-e2e@example.com';

    /**
     * The back-end's URI, as Winter's config/cms.php sets it.
     */
    protected const BACKEND_URI = 'backend';

    /**
     * A signed-in browser keeps its sign-in, and an anonymous browser served by the same worker in
     * between never gets that sign-in, its session ID, its CSRF token or its cookies.
     *
     * @return void
     */
    public function testASignedInBrowserAndAnAnonymousBrowserDoNotShareASession(): void
    {
        $password = 'E2e-' . bin2hex(random_bytes(8));
        $this->seedBackendUser($password);

        $this->startServer();

        $backend = '/' . static::BACKEND_URI;

        $browserA = [];
        $browserB = [];

        /*
         * A opens the sign-in form and posts it with the CSRF token the form carries.
         */
        $form = $this->browse('GET', $backend . '/backend/auth/signin', $browserA);
        $this->assertSame(200, $form['status'], "the sign-in page must load. Log tail:\n" . $this->logTail());
        $this->assertSame(1, preg_match('#name="_token"[^>]*value="([^"]+)"#', $form['body'], $token), 'the sign-in form must carry a CSRF token');

        $signIn = $this->browse('POST', $backend . '/backend/auth/signin', $browserA, [
            '_token' => $token[1],
            'postback' => '1',
            'login' => static::LOGIN,
            'password' => $password,
        ]);
        $this->assertSame(302, $signIn['status'], "signing in must redirect. Body:\n" . substr($signIn['body'], 0, 500));
        $this->assertStringNotContainsString('backend/auth', $this->location($signIn), 'signing in must not return to the sign-in form');

        $a1 = $this->whoami($browserA);
        $this->assertTrue($a1['signed_in'], 'A must be signed in after the sign-in form');
        $this->assertSame(static::LOGIN, $a1['login']);

        $this->assertReachesTheBackEnd($browserA, $backend, 'A');

        /*
         * B arrives with no cookies, on the same worker, straight after A.
         */
        $b1 = $this->whoami($browserB);
        $this->assertSame($a1['pid'], $b1['pid'], 'B was served by another worker process, so this run proves nothing');
        $this->assertAnonymous($b1, $a1, 'B');
        $this->assertNoCookieOf($browserA, $browserB, 'B');

        $bBackend = $this->browse('GET', $backend . '/backend', $browserB);
        $this->assertSame(302, $bBackend['status'], 'B must be sent to the sign-in page');
        $this->assertStringContainsString('backend/auth', $this->location($bBackend), 'B must be sent to the sign-in page');
        $this->assertNoCookieOf($browserA, $browserB, 'B, after asking for the back-end');

        /*
         * A comes back after B and is still signed in, in the same session.
         */
        $a2 = $this->whoami($browserA);
        $this->assertSame($a1['pid'], $a2['pid'], 'A was served by another worker process, so this run proves nothing');
        $this->assertTrue($a2['signed_in'], 'A must still be signed in after B\'s requests');
        $this->assertSame(static::LOGIN, $a2['login']);
        $this->assertSame($a1['session'], $a2['session'], 'A must keep its session');
        $this->assertSame($a1['token'], $a2['token'], 'A must keep its CSRF token');

        $this->assertReachesTheBackEnd($browserA, $backend, 'A, after B');

        /*
         * And B, served again straight after A, is still anonymous, in its own session.
         */
        $b2 = $this->whoami($browserB);
        $this->assertAnonymous($b2, $a2, 'B, the second time');
        $this->assertSame($b1['session'], $b2['session'], 'B must keep its own session');
        $this->assertNoCookieOf($browserA, $browserB, 'B, the second time');
    }

    /**
     * Asserts that a browser saw no sign-in, and not the session or token of the signed-in browser.
     *
     * @param array<string, mixed> $seen What the anonymous browser's request reported.
     * @param array<string, mixed> $signedIn What the signed-in browser's request reported.
     * @param string $who
     * @return void
     */
    protected function assertAnonymous(array $seen, array $signedIn, string $who): void
    {
        $this->assertFalse($seen['signed_in'], "{$who} must not be signed in");
        $this->assertNull($seen['login'], "{$who} must not see a user");
        $this->assertNotSame($signedIn['session'], $seen['session'], "{$who} must not get the signed-in session ID");
        $this->assertNotSame($signedIn['token'], $seen['token'], "{$who} must not get the signed-in CSRF token");
    }

    /**
     * Asserts that a browser reaches a back-end page, following the redirect the back-end home sends
     * a user without the dashboard permission.
     *
     * @param array<string, string> $jar
     * @param string $backend
     * @param string $who
     * @return void
     */
    protected function assertReachesTheBackEnd(array &$jar, string $backend, string $who): void
    {
        $response = $this->browse('GET', $backend, $jar);

        if ($response['status'] === 302) {
            $location = $this->location($response);
            $this->assertStringNotContainsString('backend/auth', $location, "{$who} must not be sent to the sign-in page");

            $response = $this->browse('GET', (string) parse_url($location, PHP_URL_PATH), $jar);
        }

        $this->assertSame(200, $response['status'], "{$who} must reach a back-end page");
        $this->assertStringContainsString('Octane E2E', $response['body'], "{$who}'s back-end page must show the signed-in user");
    }

    /**
     * Asserts that the anonymous browser holds none of the signed-in browser's cookies.
     *
     * @param array<string, string> $signedIn The signed-in browser's cookies.
     * @param array<string, string> $anonymous The anonymous browser's cookies.
     * @param string $who
     * @return void
     */
    protected function assertNoCookieOf(array $signedIn, array $anonymous, string $who): void
    {
        $this->assertArrayNotHasKey('admin_auth', $anonymous, "{$who} must not be sent the remember cookie");
        $this->assertSame(
            [],
            array_values(array_intersect($anonymous, $signedIn)),
            "{$who} must not be sent any cookie value the signed-in browser holds"
        );
    }

    /**
     * The redirect target of a response.
     *
     * @param array{headers: list<string>} $response
     * @return string
     */
    protected function location(array $response): string
    {
        foreach ($response['headers'] as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, strlen('Location:')));
            }
        }

        return '';
    }

    /**
     * Create the back-end user the test signs in, if it does not exist, and give it a password.
     *
     * The user is checked through winter:passwd, which fails for a user that does not exist, because
     * user:create throws after it has saved the user (Winter's UserCreate prints an undefined $role).
     *
     * @param string $password
     * @return void
     */
    protected function seedBackendUser(string $password): void
    {
        $created = $this->artisan([
            'user:create',
            static::LOGIN,
            '--password=' . $password,
            '--fname=Octane',
            '--lname=E2E',
            '--role=developer',
            '--force',
        ]);

        $output = $this->artisan(['winter:passwd', static::LOGIN, $password]);

        $this->assertStringContainsString(
            'Password successfully changed',
            $output,
            "the test user could not be created:\n" . $created . "\n" . $output
        );
    }

    /**
     * Who the browser's session belongs to, according to the server.
     *
     * @param array<string, string> $jar
     * @return array<string, mixed>
     */
    protected function whoami(array &$jar): array
    {
        $response = $this->browse('GET', '/_octane-smoke/whoami', $jar);

        $this->assertSame(200, $response['status'], "whoami failed. Body:\n" . substr($response['body'], 0, 500) . "\nLog tail:\n" . $this->logTail());

        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
