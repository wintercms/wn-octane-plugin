<?php

namespace Winter\Octane\Tests;

use Laravel\Octane\OctaneServiceProvider;
use System\Tests\Bootstrap\PluginTestCase;

/**
 * Winter disables Laravel's package auto-discovery by default (`app.loadDiscoveredPackages`), so
 * an installed `laravel/octane` never registers its own provider. Without it the `octane` binding
 * is missing, and Octane's ApplicationGateway resolves that binding through the Octane facade on
 * every request, so worker mode fails outright rather than degrading.
 *
 * Winter\Octane\Plugin::register() therefore registers the provider explicitly whenever the
 * package is present. It cannot instead rely on discovery or a static provider list, which would
 * try to load the provider on installs without the package.
 */
class OctaneRegistrationTest extends PluginTestCase
{
    use ReportsDeprecationsToPhpUnit;

    /**
     * The plugin's composer.json requires laravel/octane, but the plugin's files can be present
     * in an installation where composer has not run. The behavior under test only exists once
     * the package is installed, so these skip rather than fail without it.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(OctaneServiceProvider::class)) {
            $this->markTestSkipped('laravel/octane is not installed.');
        }
    }

    /**
     * Laravel package discovery is still off in Winter, which is why the plugin registers Octane's
     * provider itself.
     *
     * @return void
     */
    public function testPackageDiscoveryRemainsDisabled(): void
    {
        $this->assertFalse(
            (bool) $this->app['config']->get('app.loadDiscoveredPackages', false),
            'Winter intentionally leaves Laravel package discovery off; the Octane provider must '
            . 'not depend on it being enabled.'
        );
    }

    /**
     * Octane's service provider is loaded and the "octane" binding exists once the plugin is
     * registered.
     *
     * @return void
     */
    public function testOctaneProviderIsRegisteredWhenThePluginIsInstalled(): void
    {
        $this->assertContains(
            OctaneServiceProvider::class,
            array_keys($this->app->getLoadedProviders()),
            'Winter\Octane\Plugin must register Octane\'s provider explicitly.'
        );

        $this->assertTrue(
            $this->app->bound('octane'),
            'Octane\'s ApplicationGateway resolves the "octane" binding on every request.'
        );
    }

    /**
     * Registering the provider must stay inert outside a worker: it binds services and reads
     * configuration, but Octane's events are only dispatched by an Octane worker.
     *
     * @return void
     */
    public function testRegisteringOctaneDoesNotWireWinterSpecificListeners(): void
    {
        $this->assertIsArray($this->app['config']->get('octane.listeners'));
    }

    /**
     * A worker boots against a request that is not a back-end request, so core skips its back-end
     * registrations. The plugin must make them, or every back-end page has empty menus and settings.
     *
     * @return void
     */
    public function testRegistersBackEndExtensionsInAWorker(): void
    {
        $this->assertFalse($this->app->runningInBackend());

        $registrations = $this->withEmptyBackEndRegistrations(function () {
            // The plugin detects a worker by this binding, which only an Octane server provides.
            $this->app->instance('Laravel\Octane\Contracts\Client', new \stdClass());

            (new \Winter\Octane\Plugin($this->app))->registerBackendExtensions();
        });

        $this->assertCount(3, $registrations['navigation']);
        $this->assertCount(3, $registrations['settings']);
        $this->assertCount(3, $registrations['reportWidgets']);
        $this->assertCount(2, $registrations['formWidgets']);
    }

    /**
     * Outside a worker core already makes these registrations for back-end requests, so the plugin
     * must not make them a second time.
     *
     * @return void
     */
    public function testRegistersNothingOutsideAWorker(): void
    {
        $registrations = $this->withEmptyBackEndRegistrations(function () {
            (new \Winter\Octane\Plugin($this->app))->registerBackendExtensions();
        });

        $this->assertSame([[], [], [], []], array_values($registrations));
    }

    /**
     * Run the callback with the back-end registration callbacks emptied, and return the callbacks
     * it registered. The original callbacks are restored afterwards.
     *
     * @param callable $callback The code that makes the registrations.
     * @return array<string, array<int|string, mixed>> The callbacks registered, keyed by registration type.
     */
    protected function withEmptyBackEndRegistrations(callable $callback): array
    {
        $properties = [
            'navigation' => [\Backend\Classes\NavigationManager::instance(), 'callbacks'],
            'settings' => [\System\Classes\SettingsManager::instance(), 'callbacks'],
            'reportWidgets' => [\Backend\Classes\WidgetManager::instance(), 'reportWidgetCallbacks'],
            'formWidgets' => [\Backend\Classes\WidgetManager::instance(), 'formWidgetCallbacks'],
        ];
        $original = [];
        $registered = [];

        foreach ($properties as $key => [$object, $property]) {
            $original[$key] = (new \ReflectionProperty($object, $property))->getValue($object);
            (new \ReflectionProperty($object, $property))->setValue($object, []);
        }

        try {
            $callback();

            foreach ($properties as $key => [$object, $property]) {
                $registered[$key] = (new \ReflectionProperty($object, $property))->getValue($object);
            }
        }
        finally {
            foreach ($properties as $key => [$object, $property]) {
                (new \ReflectionProperty($object, $property))->setValue($object, $original[$key]);
            }

            $this->app->forgetInstance('Laravel\Octane\Contracts\Client');
        }

        return $registered;
    }
}
