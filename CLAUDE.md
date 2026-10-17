# CLAUDE.md

Guidance for AI coding agents working in this repository.

## Project

`winter/wn-octane-plugin` serves Winter CMS from a persistent Laravel Octane worker (FrankenPHP,
Swoole or RoadRunner). Read `README.md` for what the plugin does, and `REVIEW.md` for the rules a
change is reviewed against. The most important one: nothing a request leaves in memory may reach
the next request.

Key files:

- `Plugin.php`: registers Octane's provider, the back-end registrations a worker needs, the reset
  listeners and the client-address middleware.
- `classes/ResetsRequestState.php`: clears request state at the start of every Octane operation.
  The static caches it clears are listed in its `STATIC_CACHES`, `MANAGER_STATE` and
  `TRAIT_STATIC_CACHES` constants.
- `tests/PersistentWorkerTestCase.php`: dispatches requests through Octane's real `Worker` against
  one application. Use it for anything that crosses a request boundary.

## Setup

The plugin runs inside a Winter installation, at `plugins/winter/octane`. The tests, PHPCS and
PHPStan all use the installation's `vendor/`. To match CI (`.github/workflows/tests.yml`):

```bash
git clone -b wip/1.3 https://github.com/wintercms/winter.git
cd winter
composer install
composer require laravel/octane:^2.5 --with-all-dependencies
composer require --dev larastan/larastan:3.13.0 --with-all-dependencies
git checkout -- modules
git clone https://github.com/wintercms/wn-octane-plugin.git plugins/winter/octane
cp .env.example .env && php artisan key:generate
touch storage/database.sqlite   # then set DB_CONNECTION=sqlite and DB_DATABASE=storage/database.sqlite in .env
php artisan winter:up
```

`composer install` puts Winter's module packages into `modules/`, which can lag the checkout;
`git checkout -- modules` restores them.

## Commands

Run these from `plugins/winter/octane`:

- Tests: `../../../vendor/bin/phpunit --testsuite Octane --fail-on-skipped`
- End-to-end test (starts a real FrankenPHP server): `WINTER_OCTANE_E2E=1 ../../../vendor/bin/phpunit --testsuite E2e --fail-on-skipped`
- One test: `../../../vendor/bin/phpunit --filter testName`
- Code style: `../../../vendor/bin/phpcs --standard=phpcs.xml.dist`, fix with `../../../vendor/bin/phpcbf --standard=phpcs.xml.dist`
- Static analysis: `../../../vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G`
- Syntax: `../../../vendor/bin/parallel-lint Plugin.php routes.php classes contracts tests`
- Composer metadata: `composer validate --strict --no-check-lock`
- Review script tests: `node --test .github/scripts/code-review.test.js`
- Workflow lint: `actionlint`

Run the tests, PHPCS and PHPStan before pushing.

## Conventions

- Winter's coding style: PSR-2, with `catch` and `else` on a new line after the closing brace.
  `phpcs.xml.dist` enforces it.
- PHPStan runs at level 8 with Larastan. Fix the type; do not add a baseline or an inline ignore
  without a reason in `phpstan.neon.dist`.
- The test suite fails on a PHP deprecation raised in the plugin's own files (`phpunit.xml`'s
  `<source>`). A new test class that extends `PluginTestCase` directly needs
  `use ReportsDeprecationsToPhpUnit;`, or the application's error handler hides its deprecations.
  Mark a test that triggers one on purpose with `#[IgnoreDeprecations]`.
- Optional dependencies (`Laravel\Octane`, `Backend`, `Cms`) are referenced by fully qualified
  name and checked with `class_exists()` where they may be missing.
- Every change to per-request state needs a test that dispatches at least two requests through
  `PersistentWorkerTestCase` and fails without the change.
- When a change depends on a core Winter or Storm pull request, or makes one unnecessary, update
  the README's requirements.
- GitHub Actions are pinned to a full commit SHA with the version in a comment. Dependabot keeps
  them current.

## Pull requests

- Target `main`.
- Describe what changed and why in a few sentences, then how it was tested.
- The `Code Review` workflow posts an automated review from a model called through OpenRouter: it
  requests changes or approves. Do not merge while its latest review requests changes; fix the
  finding and let it review again (request a review on the pull request to re-run it on an unchanged
  head). It skips pull requests from forks.
