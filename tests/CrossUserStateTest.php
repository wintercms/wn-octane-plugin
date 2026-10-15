<?php

namespace Winter\Octane\Tests;

use Backend\Classes\BackendController;
use Backend\Classes\NavigationManager;
use Backend\Classes\WidgetManager;
use Illuminate\Http\Request;
use System\Classes\SettingsManager;

/**
 * The backend managers cache lists after filtering them by the authenticated user's permissions,
 * and they are resolved into the base container while providers register, so the request sandbox
 * never discards them.
 *
 * Without a reset the first user a worker serves decides what every later user sees. These tests
 * run through Octane's real gateway, so a failure here is the failure a worker would exhibit.
 */
class CrossUserStateTest extends PersistentWorkerTestCase
{
    /**
     * Resolve the managers against the base application before any request is dispatched.
     *
     * This is the precondition that makes the leak possible, and it is what production does: the
     * module providers resolve these managers while registering, so they live on the container every
     * request sandbox is cloned from and flushing the sandbox cannot discard them. A manager first
     * resolved inside a sandbox would be discarded with it, which would mask the defect.
     */
    protected function bootWorker(): \Illuminate\Foundation\Application
    {
        $alreadyBooted = $this->workerApplication !== null;

        $app = parent::bootWorker();

        if (!$alreadyBooted) {
            NavigationManager::instance($app);
            WidgetManager::instance($app);
            SettingsManager::instance($app);
        }

        return $app;
    }

    protected function readProtected(object $target, string $property)
    {
        $prop = (new \ReflectionObject($target))->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($target);
    }

    /**
     * loadItems() stores the permission-filtered menu, and only rebuilds it when it is null.
     */
    public function testNavigationItemsAreRebuiltForEachRequest()
    {
        $seen = [];

        $this->addWorkerRoute('_worker/nav', function () use (&$seen) {
            $manager = NavigationManager::instance();
            $prop = (new \ReflectionObject($manager))->getProperty('items');
            $prop->setAccessible(true);

            // What the previous request left behind.
            $seen[] = $prop->getValue($manager);

            // Stand in for the filtered result a real request would cache.
            $prop->setValue($manager, ['left-over-from-a-previous-user']);

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/nav', 'GET'),
            Request::create('/_worker/nav', 'GET')
        );

        $this->assertCount(2, $seen);
        $this->assertNull($seen[1], 'a filtered navigation list must not be reused for the next user');
    }

    public function testNavigationContextDoesNotCrossRequests()
    {
        $seen = [];

        $this->addWorkerRoute('_worker/nav-context', function () use (&$seen) {
            $manager = NavigationManager::instance();
            $seen[] = $manager->getContext();

            $manager->setContext('Acme.Demo', 'previous-owner', 'previous-side-item');

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/nav-context', 'GET'),
            Request::create('/_worker/nav-context', 'GET')
        );

        $this->assertCount(2, $seen);
        $this->assertNull(
            $seen[1]->mainMenuCode ?? null,
            'the active navigation context must not carry over to the next request'
        );
    }

    /**
     * listReportWidgets() unsets entries the current user cannot access directly from the cached
     * list, so a user without permission removed the widget for everyone the worker served next.
     */
    public function testReportWidgetsAreRebuiltForEachRequest()
    {
        $seen = [];

        $this->addWorkerRoute('_worker/widgets', function () use (&$seen) {
            $manager = WidgetManager::instance();
            $prop = (new \ReflectionObject($manager))->getProperty('reportWidgets');
            $prop->setAccessible(true);

            $seen[] = $prop->getValue($manager);

            // Stand in for a list a permission check has already pruned.
            $prop->setValue($manager, ['Acme\\ReportWidgets\\Survivor' => []]);

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/widgets', 'GET'),
            Request::create('/_worker/widgets', 'GET')
        );

        $this->assertCount(2, $seen);
        $this->assertNull($seen[1], 'a pruned report widget list must not be reused for the next user');
    }

    /**
     * Registration data is built once per worker; discarding it would leave the worker with no
     * navigation or widgets at all, which is worse than serving stale ones.
     */
    public function testRegistrationDataSurvivesTheReset()
    {
        $navigationCallbacks = null;
        $widgetCallbacks = null;

        $this->addWorkerRoute('_worker/registration', function () use (&$navigationCallbacks, &$widgetCallbacks) {
            $navigationCallbacks = count($this->readProtected(NavigationManager::instance(), 'callbacks'));
            $widgetCallbacks = count($this->readProtected(WidgetManager::instance(), 'reportWidgetCallbacks'));

            return 'ok';
        });

        $before = count($this->readProtected(NavigationManager::instance(), 'callbacks'));

        $this->dispatchWorkerRequests(
            Request::create('/_worker/registration', 'GET'),
            Request::create('/_worker/registration', 'GET')
        );

        $this->assertSame(
            $before,
            $navigationCallbacks,
            'navigation registration callbacks must survive the reset'
        );
        $this->assertNotNull($widgetCallbacks);
    }

    public function testSettingsItemsAreRebuiltForEachRequest()
    {
        $seen = [];

        $this->addWorkerRoute('_worker/settings', function () use (&$seen) {
            $manager = SettingsManager::instance();
            $prop = (new \ReflectionObject($manager))->getProperty('items');
            $prop->setAccessible(true);

            $seen[] = $prop->getValue($manager);
            $prop->setValue($manager, ['left-over']);

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/settings', 'GET'),
            Request::create('/_worker/settings', 'GET')
        );

        $this->assertCount(2, $seen);
        $this->assertNull($seen[1], 'a filtered settings list must not be reused for the next user');
    }

    /**
     * Backend dispatch writes the resolved action and parameters onto the controller class itself.
     */
    public function testBackendControllerActionStateDoesNotCrossRequests()
    {
        $seen = [];

        $this->addWorkerRoute('_worker/action', function () use (&$seen) {
            $seen[] = [BackendController::$action, BackendController::$params];

            BackendController::$action = 'previousAction';
            BackendController::$params = ['previous', 'params'];

            return 'ok';
        });

        $this->dispatchWorkerRequests(
            Request::create('/_worker/action', 'GET'),
            Request::create('/_worker/action', 'GET')
        );

        $this->assertCount(2, $seen);
        $this->assertNull($seen[1][0], 'the previous action must not survive into the next request');
        $this->assertSame([], $seen[1][1], 'the previous parameters must not survive either');
    }
}
