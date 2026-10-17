<?php

namespace Winter\Octane\Tests;

use Backend\Facades\BackendAuth;
use Backend\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use RuntimeException;

/**
 * A back-end sign-in held in a real session does not reach the next request on the same worker.
 *
 * The other auth tests plant a user on the auth manager by reflection. These sign a seeded back-end
 * user in through the web middleware instead, so the session, the cookie jar and the encrypted cookies
 * are the real ones. The session store and the cookie jar are warmed into the base application, so
 * every request on the worker shares them. Each sequence checks both directions: a request without
 * cookies is anonymous, and a request that sends back the signed-in browser's cookies gets its own
 * session again, so the reset does not sign returning users out.
 */
class SessionDriftTest extends PersistentWorkerTestCase
{
    /**
     * @var array<int, int> IDs of the back-end users this test created, deleted again in tearDown().
     */
    protected array $createdUserIds = [];

    /**
     * @var array<string, array<string, mixed>> What each request observed, keyed by its step label.
     */
    protected array $seen = [];

    /**
     * Deletes the users this test created, with the throttle and access-log rows signing in wrote.
     *
     * The test database is a file that outlives the test, so the rows are removed directly rather
     * than through the model, whose delete is soft and checks the acting user's permissions. The
     * database manager still holds the last request's sandbox, which Octane has flushed, so it is
     * handed the base application again first.
     *
     * @return void
     */
    public function tearDown(): void
    {
        try {
            if ($this->createdUserIds !== []) {
                $db = $this->app->make('db');
                $db->setApplication($this->app);

                $db->table('backend_user_throttle')->whereIn('user_id', $this->createdUserIds)->delete();
                $db->table('backend_access_log')->whereIn('user_id', $this->createdUserIds)->delete();
                $db->table('backend_users')->whereIn('id', $this->createdUserIds)->delete();
            }
        }
        finally {
            $this->createdUserIds = [];
            $this->seen = [];

            parent::tearDown();
        }
    }

    /**
     * A signed-in session is invisible to a request without cookies, and intact for one that sends
     * the session cookie back.
     *
     * Octane's FlushSessionState empties the shared session store at the start of every request.
     * Without it, StartSession merges the stored session into the attributes the previous request
     * left in memory, so a browser with no cookies would get the previous user's sign-in and CSRF
     * token under a new session ID.
     *
     * @return void
     */
    public function testASessionSignInDoesNotReachARequestWithoutCookies(): void
    {
        $admin = $this->createBackendUser('drift_admin', true);

        $this->addSessionRoutes();
        $this->addWorkerRoute('_worker/session/login', function () use ($admin) {
            BackendAuth::login(User::findOrFail($admin->id), false);

            return $this->observe('A');
        }, 'GET', ['web']);

        $sessionCookie = $this->sessionCookieName();

        $cookiesA = $this->dispatchWorkerRequestWithCookies('/_worker/session/login');
        $this->assertArrayHasKey($sessionCookie, $cookiesA, 'signing in must send a session cookie');
        $this->assertSame($admin->id, $this->seen['A']['user']);

        $this->dispatchWorkerRequestWithCookies('/_worker/session/whoami?step=B');
        $this->assertAnonymous('B', 'A');

        $this->dispatchWorkerRequestWithCookies(
            '/_worker/session/whoami?step=C',
            [$sessionCookie => $cookiesA[$sessionCookie]]
        );
        $this->assertTrue($this->seen['C']['check'], 'the signed-in browser must still be signed in');
        $this->assertSame($admin->id, $this->seen['C']['user']);
        $this->assertSame($this->seen['A']['session'], $this->seen['C']['session'], 'the session must be the same one');
        $this->assertSame($this->seen['A']['token'], $this->seen['C']['token'], 'the CSRF token must be the same one');

        $this->dispatchWorkerRequestWithCookies('/_worker/session/whoami?step=D');
        $this->assertAnonymous('D', 'A');
    }

    /**
     * A request that signs in with "remember me" and then fails leaves no cookie for the next request.
     *
     * The failing route is an Octane route, served outside the HTTP kernel, so its exception escapes to
     * the worker: no middleware sends the queued cookies or saves the session, and RequestTerminated is
     * never dispatched. The remember cookie and the probe cookie are still queued in the shared cookie
     * jar, and the sign-in is still in the shared session store's memory, when the next request starts. Octane's FlushQueuedCookies and FlushSessionState clear them; without
     * them the next browser would be sent a remember cookie for the previous user.
     *
     * The remember cookie the failed request queued is then sent on its own, to show that a genuine
     * remember-me sign-in still works after the reset, and a last request without cookies must not
     * inherit that remember-me sign-in.
     *
     * @return void
     */
    public function testARememberCookieQueuedByAFailedRequestIsNotSentToTheNext(): void
    {
        $admin = $this->createBackendUser('drift_remember', true);
        $rememberValue = null;

        $this->addSessionRoutes();

        /*
         * An Octane route rather than addWorkerRoute(): Octane serves it outside the HTTP kernel, so
         * the exception escapes to the worker instead of being rendered as an error page.
         */
        \Laravel\Octane\Facades\Octane::route('GET', '/_worker/session/fail-remember', function () use ($admin, &$rememberValue) {
            BackendAuth::login(User::findOrFail($admin->id), true);
            Cookie::queue('probe', 'x');

            $rememberValue = Cookie::queued('admin_auth')?->getValue();

            throw new RuntimeException('failure after signing in');
        });

        $results = $this->dispatchWorkerRequests(Request::create('/_worker/session/fail-remember', 'GET'));
        $this->assertInstanceOf(RuntimeException::class, $results[0]);
        $this->assertIsString($rememberValue, 'signing in with remember must queue the remember cookie');

        $cookiesB = $this->dispatchWorkerRequestWithCookies('/_worker/session/whoami?step=B');
        $this->assertArrayNotHasKey('admin_auth', $cookiesB, 'the failed request\'s remember cookie must not be sent');
        $this->assertArrayNotHasKey('probe', $cookiesB, 'the failed request\'s queued cookie must not be sent');
        $this->assertSame([], $this->seen['B']['queued'], 'the cookie jar must start the request empty');
        $this->assertAnonymous('B');

        $this->dispatchWorkerRequestWithCookies(
            '/_worker/session/whoami?step=C',
            ['admin_auth' => $this->encryptWorkerCookie('admin_auth', $rememberValue)]
        );
        $this->assertTrue($this->seen['C']['check'], 'the remember cookie must still sign its browser in');
        $this->assertTrue($this->seen['C']['viaRemember'], 'the sign-in must come from the remember cookie');
        $this->assertSame($admin->id, $this->seen['C']['user']);

        $this->dispatchWorkerRequestWithCookies('/_worker/session/whoami?step=D');
        $this->assertAnonymous('D');
    }

    /**
     * Impersonation, which lives in the session, belongs to the browser that started it.
     *
     * Storm keeps the impersonator's ID in the session and memoizes the impersonator on the auth
     * manager. A browser without cookies must see neither; the impersonating browser must keep both
     * until it stops; and stopping impersonation in another session must not restore that session to
     * the impersonating administrator.
     *
     * @return void
     */
    public function testImpersonationStaysWithTheSessionThatStartedIt(): void
    {
        $admin = $this->createBackendUser('drift_impersonator', true);
        $target = $this->createBackendUser('drift_target', false, ['backend.manage_users' => 1]);

        $this->addSessionRoutes();
        $this->addWorkerRoute('_worker/session/impersonate', function () use ($admin, $target) {
            BackendAuth::login(User::findOrFail($admin->id), false);
            BackendAuth::impersonate(User::findOrFail($target->id));

            return $this->observe('A');
        }, 'GET', ['web']);
        $this->addWorkerRoute('_worker/session/stop', function () {
            BackendAuth::stopImpersonate();

            return $this->observe((string) request('step'));
        }, 'GET', ['web']);

        $sessionCookie = $this->sessionCookieName();

        $cookiesA = $this->dispatchWorkerRequestWithCookies('/_worker/session/impersonate');
        $this->assertArrayHasKey($sessionCookie, $cookiesA, 'impersonating must send a session cookie');
        $this->assertTrue($this->seen['A']['impersonating']);
        $this->assertSame($target->id, $this->seen['A']['user']);
        $this->assertSame($admin->id, $this->seen['A']['realUser']);

        $this->dispatchWorkerRequestWithCookies('/_worker/session/whoami?step=B');
        $this->assertAnonymous('B', 'A');

        $this->dispatchWorkerRequestWithCookies(
            '/_worker/session/whoami?step=C',
            [$sessionCookie => $cookiesA[$sessionCookie]]
        );
        $this->assertTrue($this->seen['C']['impersonating'], 'the impersonating browser must still be impersonating');
        $this->assertSame($target->id, $this->seen['C']['user']);
        $this->assertSame($admin->id, $this->seen['C']['realUser']);

        $this->dispatchWorkerRequestWithCookies('/_worker/session/stop?step=B2');
        $this->assertAnonymous('B2', 'A');

        $cookiesD = $this->dispatchWorkerRequestWithCookies(
            '/_worker/session/stop?step=D',
            [$sessionCookie => $cookiesA[$sessionCookie]]
        );
        $this->assertArrayHasKey($sessionCookie, $cookiesD);
        $this->assertFalse($this->seen['D']['impersonating']);
        $this->assertSame($admin->id, $this->seen['D']['user']);

        $this->dispatchWorkerRequestWithCookies(
            '/_worker/session/whoami?step=E',
            [$sessionCookie => $cookiesD[$sessionCookie]]
        );
        $this->assertTrue($this->seen['E']['check']);
        $this->assertFalse($this->seen['E']['impersonating'], 'stopping impersonation must persist in the session');
        $this->assertSame($admin->id, $this->seen['E']['user']);
        $this->assertSame($admin->id, $this->seen['E']['realUser']);
    }

    /**
     * Registers the web route every sequence reads its state from.
     *
     * @return void
     */
    protected function addSessionRoutes(): void
    {
        $this->addWorkerRoute('_worker/session/whoami', function () {
            return $this->observe((string) request('step'));
        }, 'GET', ['web']);
    }

    /**
     * Asserts that a request saw no signed-in user, and not the session of the request it follows.
     *
     * @param string $step The step to check.
     * @param string|null $signedIn The step that signed in, whose session and token must not be reused.
     * @return void
     */
    protected function assertAnonymous(string $step, ?string $signedIn = null): void
    {
        $seen = $this->seen[$step];

        $this->assertNull($seen['adminAuth'], "{$step}: the session must not hold a sign-in");
        $this->assertFalse($seen['impersonating'], "{$step}: the session must not be impersonating");
        $this->assertNull($seen['user'], "{$step}: no user may be signed in");
        $this->assertNull($seen['realUser'], "{$step}: no real user may be signed in");
        $this->assertFalse($seen['check'], "{$step}: check() must fail");
        $this->assertFalse($seen['viaRemember'], "{$step}: no sign-in may come from a remember cookie");

        if ($signedIn !== null) {
            $this->assertNotSame($this->seen[$signedIn]['session'], $seen['session'], "{$step}: the session ID must be new");
            $this->assertNotSame($this->seen[$signedIn]['token'], $seen['token'], "{$step}: the CSRF token must be new");
        }
    }

    /**
     * Creates an activated back-end user, recorded for deletion in tearDown().
     *
     * @param string $login
     * @param bool $superuser
     * @param array<string, int> $permissions
     * @return \Backend\Models\User
     */
    protected function createBackendUser(string $login, bool $superuser, array $permissions = []): User
    {
        $user = User::unguarded(function () use ($login, $superuser, $permissions) {
            return User::create([
                'email' => $login . '@example.com',
                'login' => $login,
                'password' => 'Drift-test-1',
                'password_confirmation' => 'Drift-test-1',
                'first_name' => 'Drift',
                'last_name' => 'Test',
                'is_superuser' => $superuser,
                'is_activated' => true,
                'permissions' => $permissions,
            ]);
        });

        $this->createdUserIds[] = $user->id;

        return $user;
    }

    /**
     * Records what the current request sees of the session and the sign-in.
     *
     * The raw session values are read before check(), which would otherwise resolve them through
     * the auth manager and hide where a leaked sign-in came from.
     *
     * @param string $step The label the observation is recorded under.
     * @return string
     */
    protected function observe(string $step): string
    {
        $adminAuth = Session::get('admin_auth');
        $impersonating = BackendAuth::isImpersonator();
        $check = BackendAuth::check();
        $realUser = BackendAuth::getRealUser();

        $this->seen[$step] = [
            'adminAuth' => $adminAuth,
            'impersonating' => $impersonating,
            'check' => $check,
            'viaRemember' => BackendAuth::viaRemember(),
            'user' => BackendAuth::getUser()?->getKey(),
            'realUser' => $realUser ? $realUser->getKey() : null,
            'session' => Session::getId(),
            'token' => csrf_token(),
            'queued' => app('cookie')->getQueuedCookies(),
        ];

        return 'ok';
    }

    /**
     * The name of the session cookie.
     *
     * @return string
     */
    protected function sessionCookieName(): string
    {
        return (string) $this->bootWorker()->make('config')->get('session.cookie');
    }
}
