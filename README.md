# Octane Plugin

[![Tests](https://github.com/austinderrick/Winter.Octane/actions/workflows/tests.yml/badge.svg)](https://github.com/austinderrick/Winter.Octane/actions/workflows/tests.yml)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/austinderrick/Winter.Octane/blob/main/LICENSE)

This plugin lets you run Winter CMS on [Laravel Octane](https://laravel.com/docs/octane), so FrankenPHP, Swoole or RoadRunner serves your site instead of PHP-FPM.

Under Octane, your app boots once and the same process handles request after request. Winter was written for PHP-FPM, where the process goes away after every request, so it never had to clean up after itself. In a worker, the logged-in user, permission-filtered menus, settings, view data and even an open database transaction would all still be there when the next request came in, possibly from someone else. Most of what this plugin does is clear that state.

What the plugin does:

- Octane's service provider gets registered. Winter turns off Laravel's package discovery, so installing `laravel/octane` on its own does nothing.
- Winter's back-end navigation, widgets and settings get registered in each worker. Winter only registers them for back-end requests, and a worker doesn't boot against one.
- Request state gets cleared at the start of every Octane operation, before Octane's own listeners run. Core also changes a few things and doesn't always change them back: mass-assignment protection after a failed migration, the YAML processor after a failed parse, and the `exception.beforeRender` listeners that every back-end request adds. Those go back to how they were when the worker booted.
- FrankenPHP, Swoole and RoadRunner serve only the files Winter's `.htaccess` whitelists.
- The back-end auth manager gets the client address on each request, after the trusted-proxy middleware has run, so login throttling applies to the right client.
- Your own plugins can hook in: define `resetWorkerState()` to clear their request state, or `keepWorkerCacheValue()` to keep cache values in memory.

## Requirements

- Winter CMS 1.3 with [wintercms/storm#241](https://github.com/wintercms/storm/pull/241). That pull request fixes event listeners registered against an interface, and Octane uses one for its cleanup.
- PHP 8.1 or later.
- An application server Octane supports: FrankenPHP, Swoole or RoadRunner. You can start it with `octane:start` or with `octane:frankenphp`, `octane:swoole` or `octane:roadrunner`. The plugin replaces those last three commands so that only Winter's public files get served (more on that below).

## Installation

Install the plugin with [Composer](http://getcomposer.org/):

```bash
composer require winter/wn-octane-plugin
```

Next, run the migrations and install Octane. If you use a [public folder](https://wintercms.com/docs/develop/docs/setup/configuration#using-a-public-folder), [republish your public directory](https://wintercms.com/docs/develop/docs/console/setup-maintenance#mirror-public-files) too.

```bash
php artisan migrate
php artisan octane:install
```

Then start a worker:

```bash
php artisan octane:start
```

For server configuration, deployment and tuning, see the [Octane documentation](https://laravel.com/docs/octane).

### FrankenPHP

Start the server with `php artisan octane:frankenphp`. The plugin swaps Octane's command for one that understands Winter's layout, which changes three things.

**The Caddyfile.** Winter serves from the project root, so Octane's own Caddyfile would hand out `composer.json`, `.env` and your logs, and would run any PHP file in the project. The plugin's [`caddy/Caddyfile`](caddy/Caddyfile) serves only what Winter's `.htaccess` whitelists (`storage/app/uploads/public`, `storage/app/media`, `storage/app/resized`, `storage/temp/public`, `.well-known`, and the `assets` and `resources` folders of themes, plugins and modules) and sends everything else to the worker. You get this file unless you pass `--caddyfile`. If you bring your own, apply the same whitelist.

The Caddyfile sends `Cache-Control: max-age=604800, public, must-revalidate` with those files, so browsers and CDNs keep them for a week. To set FrankenPHP's global options, such as `max_idle_time` or `max_wait_time`, put them in the `WINTER_OCTANE_FRANKENPHP_OPTIONS` environment variable or in `octane.caddy.env`, one option per line. The Caddyfile inserts them in its `frankenphp` block.

**Restricted back-end pages.** On the back-end's sign-in (`backend/auth`), update (`system/updates`) and install (`system/install`) pages, Winter under PHP-FPM registers only its elevated plugins, so you can still sign in and disable a plugin that broke the back end. A worker boots once with every plugin loaded, so the Caddyfile sends those paths, under your `cms.backendUri`, to Winter's `index.php` on a regular PHP thread, which boots for each request the way PHP-FPM does.

Those pages pay for a full boot every time and share FrankenPHP's regular threads, so raise its `num_threads` option if you get bursts of sign-ins or run long updates. Core's other restricted path, `/combine/`, stays on the worker, which already has every plugin booted. If you bring your own Caddyfile, add the `@restricted` rewrite before the worker rewrite. The command sets the `WINTER_OCTANE_RESTRICTED_PATHS` value it reads.

**The worker file.** If `frankenphp-worker.php` is missing, or is the untouched copy that `octane:install` writes, the plugin installs [its own](caddy/frankenphp-worker.php). Octane's version looks for your app one directory above the web root, and it loads Composer's autoloader without Winter's `bootstrap/autoload.php`, which lets Laravel's global helpers such as `trans()` replace Winter's. The plugin leaves any other `frankenphp-worker.php` alone. If yours came from an earlier version of this README, delete it so the plugin can install the current one.

### Swoole and RoadRunner

Winter serves from the project root, so Octane's Swoole and RoadRunner commands would serve any file in your project, including `.env`, `composer.json`, your logs and your SQLite database. RoadRunner would also serve the source of every PHP file. Neither server can limit static files to a list of paths, so the plugin's `octane:swoole` and `octane:roadrunner` commands turn static file serving off:

- **Swoole:** the plugin sets `octane.serve_static_files` to `false` when the server starts. If your version of Octane still serves `composer.json` with that setting, the command won't start the server. It also won't start if you've turned on Swoole's own `enable_static_handler` option in `octane.swoole.options`, because that would serve the whole project root again.
- **RoadRunner:** the plugin removes `static` from `octane.roadrunner.http_middleware`. RoadRunner won't start with an empty list, so if `static` was the only entry, the plugin replaces it with `gzip`, which compresses responses as the plugin's Caddyfile does. If your version of Octane no longer reads that setting, the command won't start the server.

The worker then serves the same whitelisted paths as the Caddyfile and passes everything else to Winter. Every file served this way goes through Octane and Winter's global middleware, so on a busy site, put a web server or CDN in front to serve those paths.

These two servers can't send a request anywhere but the worker, so non-elevated plugins stay loaded on the back-end's sign-in, update and install pages, unlike under PHP-FPM or FrankenPHP. If a non-elevated plugin breaks the back end, it breaks the sign-in page too.

## Performance

A long-running worker can hold on to work that PHP-FPM has to repeat, so the plugin does that for you. There's nothing to configure, and none of it runs under PHP-FPM.

**Theme templates.** If a theme reads from a plain `FileDatasource`, the worker reads it through `WorkerFileDatasource` instead. That class remembers resolved paths and file contents, but still stats the file on every read, so you'll see a template change on the next read. It leaves subclasses of `FileDatasource` alone, along with the `AutoDatasource` that core builds for child themes and database templates.

Symlinks inside a theme get checked on every read. If you repoint a symlink on the theme directory or a directory above it while a worker is running, reload the worker.

**Parsed YAML.** With `app.debug` off, values cached by `Yaml::parseFile()` stay in the worker's memory between requests. The file's modification time is part of the cache key, so an edit that changes it gets parsed again.

**The class scan.** The request-state reset has to find the classes that use certain traits. The first full scan now happens when the worker starts, so your first request doesn't pay for it.

## Disabling the plugin

Don't disable this plugin while Octane is serving your site. A disabled plugin never loads, so the reset stops running while the workers keep serving requests, and users end up sharing request state, including who's logged in. To stop using Octane, switch back to PHP-FPM first. Once no worker is running, the plugin does nothing and you can safely disable or remove it.

The plugin is registered as `elevated` so that it still loads on the protected routes and console commands where Winter skips normal plugin initialization.

## For plugin developers

If your plugin caches request data on the plugin class, a singleton or a static property, define `resetWorkerState()` to clear it. It runs at the start of every Octane operation:

```php
public function resetWorkerState(): void
{
    $this->somePerRequestCache = [];
}
```

A few things to watch for:

- It can run more than once per request, for example after a request throws partway through, so make sure it's safe to run repeatedly.
- Only clear data created during a request. Event listeners, navigation items and other registrations are built once per worker, and they won't come back if you discard them.
- Don't read the current request here. This method runs before the incoming request is bound, and its only job is to clean up after the previous one.
- Don't change configuration or resolve services here either. This method runs before Octane gives the operation its own copy of the configuration and points services at the new application, so a `Config::set()` here changes the configuration for every later request.
- Listeners you add to `exception.beforeRender` during a request last only for that request, as they do under PHP-FPM. Winter's back end adds one on every request, so the reset restores that event's listeners to the ones registered while the worker booted. To keep a listener, register it from `register()` or `boot()`.
- Don't put registrations behind `App::runningInBackend()` in `register()` or `boot()`. A worker runs those methods once, against a request that isn't a back-end request, so anything behind that check never gets registered. Registrations that only store a callback, such as navigation and settings, cost little, so make them unconditionally.

If your plugin requires this one, you can add `implements \Winter\Octane\Contracts\ResetsWorkerState` for the compile-time check. PHP won't load a class that implements a missing interface, so if your plugin also runs without this one installed, define the method and skip the interface.

### Keeping cache values between requests

The reset also empties the in-memory layer of Winter's cache stores, so apart from parsed YAML, anything your request read from the cache gets read from the store again next time. To keep values that every request needs, such as site-wide settings, define `keepWorkerCacheValue()`. It runs at the start of every Octane operation for each key in memory, and a value stays when it returns `true`:

```php
public function keepWorkerCacheValue(string $key, $value): bool
{
    return str_starts_with($key, 'acme.site.');
}
```

A few things to watch for:

- Only scalars, nulls and arrays of those can stay. Objects and arrays holding PHP references always get flushed, so one request can't change what the next one reads.
- It's up to your plugin to decide when a kept value is out of date. A change made by another worker or process won't reach this worker's memory, so return `false` once the value may have changed, for example by comparing a version number your plugin stores in the cache.
- If your method throws, the exception gets logged and that key gets flushed. Nothing stays when the worker itself handles an error, such as an exception that escapes the HTTP kernel.
- This method also runs before the incoming request is bound, so don't read the current request here. If your plugin requires this one, you can add `implements \Winter\Octane\Contracts\KeepsWorkerCacheValues`.

## Tests

The test suite boots one application and sends requests through Octane's `ApplicationGateway`, the same path a worker uses. Run it from the plugin directory inside a Winter installation:

```bash
../../../vendor/bin/phpunit
```

## License

This plugin is licensed under the [MIT license](LICENSE).
