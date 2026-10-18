<?php

namespace Winter\Octane\Tests;

use Backend\Classes\BackendController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use System\Classes\VersionYamlProcessor;
use Throwable;
use Winter\Storm\Database\Updater;
use Winter\Storm\Support\Facades\Yaml;

/**
 * State that core changes for the length of a call, and fails to change back when the call throws or
 * when the request ends, is put back to the state the worker booted with.
 */
class BootStateResetTest extends PersistentWorkerTestCase
{
    /**
     * YAML that Symfony's parser rejects, and that VersionYamlProcessor rescues by quoting every value.
     */
    protected const INVALID_YAML = "enabled: true\ncount: 5\nnote: !!! not a tag";

    /**
     * Puts mass-assignment protection back on, in case a test failed with it off.
     *
     * @return void
     */
    public function tearDown(): void
    {
        Model::reguard();

        parent::tearDown();
    }

    /**
     * A migration that throws inside Storm's Updater does not leave models open to mass assignment for
     * the next request.
     *
     * @return void
     */
    public function testAFailedMigrationDoesNotLeaveModelsUnguarded(): void
    {
        $this->addWorkerRoute('_boot/migrate', function () {
            (new Updater())->setUp(__DIR__ . '/fixtures/throwing_migration.php');

            return 'unreachable';
        }, 'POST');
        $this->addWorkerRoute('_boot/guard', fn () => Model::isUnguarded() ? 'unguarded' : 'guarded');

        [$before, $migrate, $after] = $this->dispatchWorkerRequests(
            Request::create('/_boot/guard'),
            Request::create('/_boot/migrate', 'POST'),
            Request::create('/_boot/guard')
        );

        $this->assertSame('guarded', $this->content($before));
        $this->assertSame(500, $migrate instanceof Response ? $migrate->getStatusCode() : null, 'The migration should have failed.');
        $this->assertSame('guarded', $this->content($after), 'Mass-assignment protection must be back on for the next request.');
    }

    /**
     * Models unguarded while the worker boots stay unguarded, as they are for every request under
     * PHP-FPM.
     *
     * @return void
     */
    public function testModelsUnguardedAtBootStayUnguarded(): void
    {
        Model::unguard();

        $this->addWorkerRoute('_boot/guard', fn () => Model::isUnguarded() ? 'unguarded' : 'guarded');

        $results = $this->dispatchWorkerRequests(
            Request::create('/_boot/guard'),
            Request::create('/_boot/guard')
        );

        $this->assertSame(['unguarded', 'unguarded'], array_map(fn ($result) => $this->content($result), $results));
    }

    /**
     * Back-end requests do not add to the exception.beforeRender listeners that later requests run.
     *
     * @return void
     */
    public function testBackEndRequestsDoNotPileUpExceptionRenderListeners(): void
    {
        $this->addWorkerRoute('_boot/listeners', fn () => (string) count(app('events')->getListeners('exception.beforeRender')));

        $requests = [Request::create('/_boot/listeners')];

        for ($i = 0; $i < 4; $i++) {
            $requests[] = Request::create('/backend/backend/auth/signin');
            $requests[] = Request::create('/_boot/listeners');
        }

        $results = $this->dispatchWorkerRequests(...$requests);
        $counts = [];

        foreach ($results as $index => $result) {
            if ($index % 2 === 1) {
                $this->assertSame(200, $result instanceof Response ? $result->getStatusCode() : null, 'The back-end sign-in page did not load.');
            }
            else {
                $counts[] = $this->content($result);
            }
        }

        $this->assertSame(array_fill(0, 5, $counts[0]), $counts, 'The listener count must not grow with each back-end request.');
    }

    /**
     * A front-end 403 renders the same page after a back-end request as before one, rather than the
     * back-end's access denied page.
     *
     * @return void
     */
    public function testAFrontEndForbiddenPageIsUnchangedByAnEarlierBackEndRequest(): void
    {
        $this->bootWorker()->make('config')->set('app.debug', true);
        $this->addWorkerRoute('_boot/forbidden', fn () => abort(403));

        [$before, $backend, $after] = $this->dispatchWorkerRequests(
            Request::create('/_boot/forbidden'),
            Request::create('/backend/backend/auth/signin'),
            Request::create('/_boot/forbidden')
        );

        $this->assertSame(200, $backend instanceof Response ? $backend->getStatusCode() : null, 'The back-end sign-in page did not load.');
        $this->assertSame(403, $after instanceof Response ? $after->getStatusCode() : null);
        $this->assertSame($this->content($before), $this->content($after));
        $this->assertStringNotContainsString('icon-lock', $this->content($after), 'The back-end access denied page was rendered.');
    }

    /**
     * A front-end 403 after a back-end request that rendered its own error page does not render the
     * back-end's page.
     *
     * Storm's dispatcher keeps each event's listeners sorted by priority once the event has been
     * dispatched. The back-end request dispatches exception.beforeRender with its own listener in
     * that sorted list, so restoring the boot-time listeners must also drop the sorted copy, or the
     * next request dispatches the back-end request's listener again.
     *
     * @return void
     */
    public function testAFrontEndForbiddenPageIsUnchangedAfterABackEndErrorPage(): void
    {
        $this->bootWorker()->make('config')->set('app.debug', true);
        $this->addWorkerRoute('_boot/forbidden', fn () => abort(403));
        $this->addWorkerRoute('_boot/backend-forbidden', function () {
            (new BackendController())->run('backend/auth/signin');

            abort(403);
        }, 'GET', ['web']);

        [$before, $backend, $after] = $this->dispatchWorkerRequests(
            Request::create('/_boot/forbidden'),
            Request::create('/_boot/backend-forbidden'),
            Request::create('/_boot/forbidden')
        );

        $this->assertStringContainsString('icon-lock', $this->content($backend), 'The back-end request must render the back-end access denied page.');
        $this->assertSame(403, $after instanceof Response ? $after->getStatusCode() : null);
        $this->assertSame($this->content($before), $this->content($after));
        $this->assertStringNotContainsString('icon-lock', $this->content($after), 'The back-end access denied page was rendered.');
    }

    /**
     * The listener a back-end request adds still renders the back-end's error page for that request,
     * after earlier requests have had theirs removed.
     *
     * @return void
     */
    public function testABackEndRequestStillRendersItsOwnErrorPage(): void
    {
        $this->bootWorker()->make('config')->set('app.debug', true);
        $this->addWorkerRoute('_boot/backend-forbidden', function () {
            (new BackendController())->run('backend/auth/signin');

            abort(403);
        }, 'GET', ['web']);

        $results = $this->dispatchWorkerRequests(
            Request::create('/_boot/backend-forbidden'),
            Request::create('/_boot/backend-forbidden')
        );

        foreach ($results as $result) {
            $this->assertSame(403, $result instanceof Response ? $result->getStatusCode() : null);
            $this->assertStringContainsString('icon-lock', $this->content($result), 'The back-end access denied page was not rendered.');
        }
    }

    /**
     * A YAML processor left on the parser by a callback that threw is not used for the next request's
     * parse.
     *
     * @return void
     */
    public function testAYamlProcessorLeftByAFailedParseIsNotUsedByTheNextRequest(): void
    {
        $this->addWorkerRoute('_boot/yaml-fails', function () {
            return Yaml::withProcessor(new VersionYamlProcessor(), function () {
                throw new RuntimeException('The version file could not be read.');
            });
        });
        $this->addWorkerRoute('_boot/yaml', function () {
            try {
                return json_encode(Yaml::parse(static::INVALID_YAML));
            }
            catch (Throwable $ex) {
                return 'rejected';
            }
        });

        [$before, $failed, $after] = $this->dispatchWorkerRequests(
            Request::create('/_boot/yaml'),
            Request::create('/_boot/yaml-fails'),
            Request::create('/_boot/yaml')
        );

        $this->assertSame('rejected', $this->content($before));
        $this->assertSame(500, $failed instanceof Response ? $failed->getStatusCode() : null, 'The parse should have failed.');
        $this->assertSame('rejected', $this->content($after), 'The next request must parse YAML without the processor.');
    }

    /**
     * The body of a response, or the message of the error the worker reported instead.
     *
     * @param \Symfony\Component\HttpFoundation\Response|\Throwable $result
     * @return string
     */
    protected function content(Response|Throwable $result): string
    {
        if ($result instanceof Throwable) {
            $this->fail('The request failed: ' . $result->getMessage());
        }

        return (string) $result->getContent();
    }
}
