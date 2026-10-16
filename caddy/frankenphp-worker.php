<?php

/*
 * Laravel Octane's FrankenPHP worker for Winter CMS. Octane passes the application and web root paths
 * in the environment. Without them, the worker assumes Winter's default layout, where both are the
 * project root rather than a public/ directory one level below it.
 */
$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? (getenv('APP_BASE_PATH') ?: __DIR__);
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? (getenv('APP_PUBLIC_PATH') ?: __DIR__);

/*
 * Winter's own autoloader loads Storm's helpers before Composer's, so that Storm's trans(), e() and
 * the rest win over Laravel's versions of the same functions. Octane's worker only loads Composer's
 * autoloader, which would let Laravel's helpers win instead.
 */
require $_SERVER['APP_BASE_PATH'] . '/bootstrap/autoload.php';

require $_SERVER['APP_BASE_PATH'] . '/vendor/laravel/octane/bin/frankenphp-worker.php';
