# Review Standards

These are the standards the automated reviewer (`.github/workflows/code-review.yml`) applies, and
what a person reviewing a pull request should check. CI already enforces style (PHPCS), types
(PHPStan with Larastan at level 8), syntax and workflow syntax, so review the behavior.

## What this plugin does

Winter CMS was written for PHP-FPM, where the process ends after every request. Under Laravel
Octane one process serves many requests, so anything a request leaves in memory reaches the next
request, which may belong to a different user. This plugin:

- registers Octane's service provider, because Winter turns off package discovery;
- registers Winter's back-end navigation, widgets and settings in a worker, because core makes
  those registrations only for back-end requests and a worker boots against a request that is
  not one;
- clears request state at the start of every Octane operation (`classes/ResetsRequestState.php`);
- records the client address on the back-end auth manager after the trusted-proxy middleware.

## Blocking issues

Ask for changes when the diff does one of these, and name the request sequence that goes wrong.

1. **Leaks state between requests.** A value set during one request (a static property, a
   singleton's field, shared view data, an open transaction, the authenticated user) is still
   there for the next one. New per-request state in core or in this plugin needs an entry in the
   reset, and a test that dispatches two requests through `PersistentWorkerTestCase`.
2. **Clears registration data.** Navigation definitions, permission and widget callbacks, event
   listeners and other registrations are built once per worker. A reset that discards them leaves
   the worker broken for the rest of its life, not just stale.
3. **Can throw on the error path.** On `WorkerErrorOccurred` the reset runs inside the worker
   loop's catch block, and an exception there kills the worker. Every step on that path must be
   guarded, and the logging that reports a failure must be guarded too.
4. **Changes the reset order.** The reset listeners on `RequestReceived`, `TaskReceived` and
   `TickReceived` are attached at `PHP_INT_MAX` priority so they run before Octane's own listeners
   and before anything a third party attaches. They must also run at the start of an operation,
   not the end: an exception that escapes the HTTP kernel skips `RequestTerminated`. The one
   exception is `WorkerErrorOccurred`: that reset is attached at `PHP_INT_MIN` so Octane reports the
   exception while the failed request's user and page are still set. It is a prompt clean-up, not
   the guarantee, because the next operation's reset still runs first.
5. **Reads the incoming request in the reset.** The reset only discards the previous operation's
   state. Anything that needs the current request belongs in middleware that runs after the
   trusted-proxy middleware.
6. **Is not inert under PHP-FPM.** Code that runs when the plugin is installed but no worker is
   running (checked with `app()->bound('Laravel\Octane\Contracts\Client')`) must not change
   behavior.
7. **Calls core internals without a fallback.** The plugin reaches into protected core methods
   and properties by name. A missing method or property must be logged and skipped, never fatal at
   worker boot, and the static-cache manifest must stay in step with core
   (`StaticCacheManifestTest`).
8. **Adds a test that cannot fail.** A test of cross-request behavior must dispatch requests
   through `PersistentWorkerTestCase`; Winter's ordinary test cases build a new application for
   every test and cannot observe a leak. Tests run with `--fail-on-skipped`, so a test that skips
   when `laravel/octane` is missing must not be the only coverage.

## Conventions

- Follow Winter's coding style: PSR-2 with Winter's exceptions (`catch` and `else` on a new line
  after the closing brace).
- Use fully qualified class names for optional dependencies (`\Laravel\Octane\...`,
  `\Backend\...`) and check `class_exists()` first where the class may be absent. A `use` statement
  or an `implements` clause naming a missing class fails when the file loads.
- Comments explain why the code does what it does in a worker, since that is rarely obvious from
  the code itself. Keep them accurate when the code changes.
- Keep the README's requirements and "Related core fixes" list current when a change depends on,
  or removes the need for, a core pull request.

## Not blocking

Wording, comment polish, alternative designs, and test refactors are optional notes.
