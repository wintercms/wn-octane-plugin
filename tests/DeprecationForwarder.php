<?php

namespace Winter\Octane\Tests;

use PHPUnit\Runner\ErrorHandler;
use Throwable;

/**
 * The error handler ReportsDeprecationsToPhpUnit installs in front of the application's.
 *
 * PHPUnit decides whether a deprecation is the plugin's own from the call stack, so this handler's
 * frame has to be one PHPUnit can skip. phpunit.xml lists handle() as a deprecation trigger for that
 * reason, which only a named method allows.
 *
 * @package winter/wn-octane-plugin
 */
final class DeprecationForwarder
{
    /**
     * @var callable|null The error handler this one was installed in front of.
     */
    public static $previous = null;

    /**
     * Records a deprecation against the running test, and passes every other error on.
     *
     * @param int $level
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool Whether the error was handled.
     */
    public static function handle(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
            try {
                (ErrorHandler::instance())($level, $message, $file, $line);

                return true;
            }
            catch (Throwable $e) {
                // No test is running (for example during shutdown): leave it to the application.
            }
        }

        if (static::$previous === null) {
            return false;
        }

        return (static::$previous)($level, $message, $file, $line) !== false;
    }
}
