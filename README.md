# Octane Plugin

[![Tests](https://github.com/austinderrick/Winter.Octane/actions/workflows/tests.yml/badge.svg)](https://github.com/austinderrick/Winter.Octane/actions/workflows/tests.yml)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/austinderrick/Winter.Octane/blob/main/LICENSE)

Adds [Laravel Octane](https://laravel.com/docs/octane) support to Winter CMS, so a site can be served by a persistent application server (FrankenPHP, Swoole or RoadRunner) instead of PHP-FPM.

With Octane, the application boots once and the same process serves many requests. Anything cached in memory during one request, such as the authenticated user, permission-filtered navigation menus, settings, view data or an open database transaction, is still present when the next request arrives, and has to be cleared so it is not served to the wrong user. Winter was written for PHP-FPM, where the whole process is discarded after every request, so it does not do this on its own.

This plugin:

- Registers Octane's service provider. Winter disables Laravel's package discovery, so installing `laravel/octane` by itself has no effect.
- Registers Winter's back-end navigation, widgets and settings in each worker. Winter registers them only for back-end requests, and a worker boots against a request that is not one.
- Clears request state at the start of every Octane operation, before Octane's own listeners run.
- Records the client address on the back-end auth manager for each request, after the trusted-proxy middleware, so login throttling is scoped to the right client.
- Calls `resetWorkerState()` on any plugin that defines it, so other plugins can clear their own request state.

## Requirements

- Winter CMS 1.3 with [wintercms/storm#241](https://github.com/wintercms/storm/pull/241), which fixes event listeners registered against an interface. Octane uses one for its cleanup.
- Recommended: the core fixes listed under [Related core fixes](#related-core-fixes).
- PHP 8.1 or later
- One of the application servers supported by Octane: FrankenPHP, Swoole or RoadRunner

## Installation

This plugin is available for installation via [Composer](http://getcomposer.org/).

```bash
composer require winter/wn-octane-plugin
```

After installing the plugin you will need to run the migrations and (if you are using a [public folder](https://wintercms.com/docs/develop/docs/setup/configuration#using-a-public-folder)) [republish your public directory](https://wintercms.com/docs/develop/docs/console/setup-maintenance#mirror-public-files).

```bash
php artisan migrate
php artisan octane:install
```

Then start a worker:

```bash
php artisan octane:start
```

Refer to the [Octane documentation](https://laravel.com/docs/octane) for server configuration, deployment and tuning.

### FrankenPHP and Winter's public path

Winter serves from the project root by default, but the `frankenphp-worker.php` file Octane generates assumes a `public/` directory one level below the project. If the worker fails to start with `worker frankenphp-worker.php has not reached frankenphp_handle_request()`, replace the generated file in your project root with:

```php
<?php

$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__;
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;

require $_SERVER['APP_BASE_PATH'].'/vendor/laravel/octane/bin/frankenphp-worker.php';
```

Octane only generates the file when it is missing, so the replacement persists. Sites [using a public folder](https://wintercms.com/docs/develop/docs/setup/configuration#using-a-public-folder) are not affected.

## Disabling the plugin

Do not disable this plugin while the site is being served by Octane. A disabled plugin is never loaded, so the request state reset stops running while the workers continue serving requests, and request state (including the authenticated user) will be shared between users. To stop using Octane, switch the site back to PHP-FPM first. Once no worker is running, the plugin has no effect and can be disabled or removed safely.

The plugin is registered as `elevated` so that it is still loaded on the protected routes and console commands where Winter skips normal plugin initialization.

## Plugin developers

If your plugin caches request data on the plugin class, a singleton or a static property, define a `resetWorkerState()` method to clear it. It is called at the start of every Octane operation:

```php
public function resetWorkerState(): void
{
    $this->somePerRequestCache = [];
}
```

Notes:

- The method may be called more than once per request (for example, after a request that threw an exception partway through), so it must be safe to run repeatedly.
- Only clear data created during a request. Event listeners, navigation items and other registrations are built once per worker and are not rebuilt if discarded.
- Do not read the current request from this method. It runs before the incoming request has been bound, and its job is only to clear the previous one.
- Do not change configuration or resolve services from this method. It runs before Octane gives the operation its own copy of the configuration and points services at the new application, so a `Config::set()` here changes the configuration for every later request.
- Do not put registrations behind `App::runningInBackend()` in `register()` or `boot()`. A worker runs those methods once, against a request that is not a back-end request, so anything behind that check is never registered. Registrations that only store a callback, such as navigation and settings, cost little, so make them unconditionally.

Plugins that require this one can declare `implements \Winter\Octane\Contracts\ResetsWorkerState` for the compile-time check. PHP will not load a class that implements a missing interface, so plugins that also run without this plugin installed should define the method without implementing the interface.

## Related core fixes

These bugs also exist under PHP-FPM, but in a worker their effects last for every later request. Each fix is a separate pull request:

- [wintercms/winter#1569](https://github.com/wintercms/winter/pull/1569): a template that fails to load leaves Twig's local file inclusion enabled. This plugin also closes it at the start of every operation.
- [wintercms/winter#1570](https://github.com/wintercms/winter/pull/1570): after a page's PHP code changes, the rebuilt class never loads and the page fails with `Class "Cms...Class" not found` until the worker restarts.
- [wintercms/storm#261](https://github.com/wintercms/storm/pull/261): an exception during `Auth\Manager::once()` leaves sessions disabled. This plugin also turns them back on at the start of every operation.
- [wintercms/storm#262](https://github.com/wintercms/storm/pull/262): an exception from a static extension method leaves the called class set.
- [wintercms/winter#1571](https://github.com/wintercms/winter/pull/1571): a page, layout or partial edited in the CMS keeps rendering its old content in a worker that already rendered it.

## Tests

The test suite boots a single application and dispatches requests through Octane's `ApplicationGateway`, the same code path a worker uses. Run it from the plugin directory inside a Winter installation:

```bash
../../../vendor/bin/phpunit
```

## License

This plugin is licensed under the [MIT license](LICENSE).
