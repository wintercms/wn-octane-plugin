<?php

/**
 * Registers the autoloader for the custom PHPStan rules. PHPStan builds its container before it runs
 * bootstrapFiles, so the rule classes have to be loadable earlier: extension.neon includes this file as a PHP
 * config file, which PHPStan runs while it reads the configuration. It adds no settings.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Winter\\Octane\\PHPStan\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if (file_exists(__DIR__ . '/' . $relative . '.php')) {
        require __DIR__ . '/' . $relative . '.php';
    }
});

return [];
