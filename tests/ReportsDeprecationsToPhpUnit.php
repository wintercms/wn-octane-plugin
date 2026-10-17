<?php

namespace Winter\Octane\Tests;

/**
 * Hands PHP deprecations back to PHPUnit while the application's error handler is installed.
 *
 * Booting the application runs Laravel's HandleExceptions bootstrapper, which replaces PHPUnit's
 * error handler for the rest of the test. Laravel sends deprecations to a log channel instead, and
 * under Winter that channel never receives them, so a deprecation in the plugin would pass the
 * suite unseen. With DeprecationForwarder in front, PHPUnit records each deprecation against the
 * running test, and phpunit.xml decides which ones count (only the plugin's own) and fails the run
 * on them. Every other error still goes to the application's handler.
 *
 * Laravel calls setUpReportsDeprecationsToPhpUnit() once the application exists, and its own
 * tear-down restores the error handler stack, removing this handler with it.
 *
 * @package winter/wn-octane-plugin
 */
trait ReportsDeprecationsToPhpUnit
{
    /**
     * Installs the deprecation handler in front of the application's error handler.
     *
     * @return void
     */
    protected function setUpReportsDeprecationsToPhpUnit(): void
    {
        DeprecationForwarder::$previous = set_error_handler([DeprecationForwarder::class, 'handle']);
    }
}
