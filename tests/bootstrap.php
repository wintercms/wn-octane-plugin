<?php

/*
 * Boots the surrounding Winter installation's test environment, then loads the test base classes
 * explicitly: PHPUnit resolves a parent class at file-load time, before the application (and with
 * it Winter's plugin-aware class loader) exists.
 */
require __DIR__ . '/../../../../modules/system/tests/bootstrap/app.php';

require_once __DIR__ . '/DeprecationForwarder.php';
require_once __DIR__ . '/ReportsDeprecationsToPhpUnit.php';
require_once __DIR__ . '/PersistentWorkerTestCase.php';
