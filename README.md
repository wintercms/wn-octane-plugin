# Octane Plugin

[![Tests](https://github.com/austinderrick/Winter.Octane/actions/workflows/tests.yml/badge.svg)](https://github.com/austinderrick/Winter.Octane/actions/workflows/tests.yml)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/austinderrick/Winter.Octane/blob/main/LICENSE)

Adds [Laravel Octane](https://laravel.com/docs/octane) support to Winter CMS, so a site can be served by a persistent application server (FrankenPHP, Swoole or RoadRunner) instead of PHP-FPM.

With Octane, the application boots once and the same process serves many requests. Anything cached in memory during one request, such as the authenticated user, permission-filtered navigation menus, settings, view data or an open database transaction, is still present when the next request arrives, and has to be cleared so it is not served to the wrong user. Winter was written for PHP-FPM, where the whole process is discarded after every request, so it does not do this on its own.

This plugin:

- Registers Octane's service provider. Winter disables Laravel's package discovery, so installing `laravel/octane` by itself has no effect.
- Registers Winter's back-end navigation, widgets and settings in each worker. Winter registers them only for back-end requests, and a worker boots against a request that is not one.
- Clears request state at the start of every Octane operation, before Octane's own listeners run. State that core changes and fails to change back (mass-assignment protection after a failed migration, the YAML processor after a failed parse, and the `exception.beforeRender` listeners each back-end request adds) is put back to the state the worker booted with.
- Serves only the files Winter's `.htaccess` whitelists, under FrankenPHP, Swoole and RoadRunner.
- Records the client address on the back-end auth manager for each request, after the trusted-proxy middleware, so login throttling is scoped to the right client.
- Calls `resetWorkerState()` on any plugin that defines it, so other plugins can clear their own request state.

## Requirements

- Winter CMS 1.3 with [wintercms/storm#241](https://github.com/wintercms/storm/pull/241), which fixes event listeners registered against an interface. Octane uses one for its cleanup.
- PHP 8.1 or later
- One of the application servers supported by Octane: FrankenPHP, Swoole or RoadRunner. Start it with `octane:start`, or with the `octane:frankenphp`, `octane:swoole` or `octane:roadrunner` command, which the plugin replaces so that only Winter's public files are served (see below)

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

### FrankenPHP

Start the server with `php artisan octane:frankenphp`. The plugin replaces Octane's command with one that suits Winter's layout:

- **Caddyfile.** Winter serves from the project root, so Octane's own Caddyfile would serve files such as `composer.json`, `.env` and the logs, and would run any PHP file in the project directly. The plugin's [`caddy/Caddyfile`](caddy/Caddyfile) serves only the paths Winter's `.htaccess` whitelists (`storage/app/uploads/public`, `storage/app/media`, `storage/app/resized`, `storage/temp/public`, `.well-known`, and the `assets` and `resources` folders of themes, plugins and modules) and sends every other request to the worker. It is used unless you pass `--caddyfile`. If you use your own Caddyfile, apply the same whitelist.
- **Worker file.** When `frankenphp-worker.php` is missing, or is the unmodified copy that `octane:install` writes, the plugin installs [its own](caddy/frankenphp-worker.php). Octane's version looks for the application one directory above the web root, and loads Composer's autoloader without Winter's `bootstrap/autoload.php`, which lets Laravel's global helpers (such as `trans()`) replace Winter's. Any other existing `frankenphp-worker.php` is kept. If yours came from an earlier version of this README, delete it so the plugin can install the current one.

### Swoole and RoadRunner

Winter serves from the project root, so Octane's Swoole and RoadRunner commands serve any file in the project, including `.env`, `composer.json`, the logs and the SQLite database, and RoadRunner also serves the source of every PHP file. Neither server can limit its static file serving to a list of paths, so the plugin's `octane:swoole` and `octane:roadrunner` commands turn it off:

- **Swoole.** The plugin sets `octane.serve_static_files` to `false` when the server starts. If the installed Octane still serves `composer.json` with that setting, the command refuses to start the server.
- **RoadRunner.** The plugin removes `static` from `octane.roadrunner.http_middleware`. RoadRunner will not start with an empty list, so when `static` was the only middleware it is replaced with `gzip`, which compresses responses as the plugin's Caddyfile does. If the installed Octane no longer reads that setting, the command refuses to start the server.

The worker then serves the files Winter's `.htaccess` whitelists, the same paths as the plugin's Caddyfile, and passes every other path to Winter. Serving a file through the worker costs a pass through Octane and Winter's global middleware, so a busy site should put a web server or CDN in front that serves the whitelisted paths itself. The plugin's `octane:swoole` refuses to start if Swoole's own `enable_static_handler` option is turned on in `octane.swoole.options`, because it would serve the whole project root again.

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
- Listeners added to `exception.beforeRender` during a request last only for that request, as they do under PHP-FPM: the reset restores that event's listeners to the ones registered while the worker booted, because Winter's back-end adds one on every request. Register a listener for it from `register()` or `boot()`.
- Do not change configuration or resolve services from this method. It runs before Octane gives the operation its own copy of the configuration and points services at the new application, so a `Config::set()` here changes the configuration for every later request.
- Do not put registrations behind `App::runningInBackend()` in `register()` or `boot()`. A worker runs those methods once, against a request that is not a back-end request, so anything behind that check is never registered. Registrations that only store a callback, such as navigation and settings, cost little, so make them unconditionally.

Plugins that require this one can declare `implements \Winter\Octane\Contracts\ResetsWorkerState` for the compile-time check. PHP will not load a class that implements a missing interface, so plugins that also run without this plugin installed should define the method without implementing the interface.

## Tests

The test suite boots a single application and dispatches requests through Octane's `ApplicationGateway`, the same code path a worker uses. Run it from the plugin directory inside a Winter installation:

```bash
../../../vendor/bin/phpunit
```

## License

This plugin is licensed under the [MIT license](LICENSE).
