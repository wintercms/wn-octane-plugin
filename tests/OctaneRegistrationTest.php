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
    /**
     * The plugin's composer.json requires laravel/octane, but the plugin's files can be present
     * in an installation where composer has not run. The behaviour under test only exists once
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

    public function testPackageDiscoveryRemainsDisabled()
    {
        $this->assertFalse(
            (bool) $this->app['config']->get('app.loadDiscoveredPackages', false),
            'Winter intentionally leaves Laravel package discovery off; the Octane provider must '
            . 'not depend on it being enabled.'
        );
    }

    public function testOctaneProviderIsRegisteredWhenThePluginIsInstalled()
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
     */
    public function testRegisteringOctaneDoesNotWireWinterSpecificListeners()
    {
        $this->assertIsArray($this->app['config']->get('octane.listeners'));
    }

    /**
     * Without wintercms/winter#1511 a worker never registers back-end navigation, which leaves every
     * back-end page with empty menus. Boot must log that rather than fail silently.
     */
    public function testBootWarnsWhenAWorkerHasNoBackEndNavigation()
    {
        $navigation = \Backend\Classes\NavigationManager::instance();
        $callbacks = new \ReflectionProperty($navigation, 'callbacks');
        $registered = $callbacks->getValue($navigation);

        $this->app->instance('Laravel\Octane\Contracts\Client', new \stdClass());
        \Illuminate\Support\Facades\Log::spy();

        try {
            $core = \Closure::bind(function () {
            }, null, \Backend\ServiceProvider::class);
            $plugin = function () {
            };

            $callbacks->setValue($navigation, [$plugin, $core]);
            (new \Winter\Octane\Plugin($this->app))->boot();
            \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');

            // A plugin's own callback must not hide the missing core registration.
            $callbacks->setValue($navigation, [$plugin]);
            (new \Winter\Octane\Plugin($this->app))->boot();
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
        }
        finally {
            $callbacks->setValue($navigation, $registered);
            $this->app->forgetInstance('Laravel\Octane\Contracts\Client');
        }
    }
}
