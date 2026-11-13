<?php

namespace Winter\Octane\PHPStan\Fixtures;

use Attribute;
use Illuminate\Console\Command;
use Winter\Octane\PHPStan\Fixtures\RequestState;

/**
 * A trait whose static is reported once for the trait, not once per class that uses it.
 */
trait RemembersKeys
{
    private static array $seen = [];

    /**
     * @param string $key
     *
     * @return void
     */
    public function remember(string $key): void
    {
        self::$seen[$key] = true; // expect: winterOctane.staticPropertyNotReset (in 2 classes)
    }
}

/**
 * First class using the trait.
 */
class FirstRememberer
{
    use RemembersKeys;
}

/**
 * Second class using the trait.
 */
class SecondRememberer
{
    use RemembersKeys;
}

/**
 * An Artisan command. An application can run a command with Artisan::call() inside a web request, so commands are
 * checked.
 */
class FixtureCommand extends Command
{
    /**
     * @return void
     */
    public function handle(): void
    {
        RequestState::$setInCommand = ['cli']; // expect: winterOctane.staticPropertyNotReset
    }
}

/**
 * Static locals: one computed only from code, one used as a flag.
 */
class StaticLocals
{
    /**
     * @return array
     */
    public static function computed(): array
    {
        static $map = null;
        $map      ??= array_flip(['a', 'b']);

        return $map;
    }

    /**
     * @param string $id
     *
     * @return array
     */
    public static function memoizedPerKey(string $id): array
    {
        static $cache = []; // expect: winterOctane.staticVariable
        $cache[$id] ??= [$id];

        return $cache[$id];
    }

    /**
     * @return int
     */
    public static function counted(): int
    {
        static $calls = 0; // expect: winterOctane.staticVariable
        $calls++;

        return $calls;
    }

    /**
     * @return bool
     */
    public static function flagged(): bool
    {
        static $done = false; // expect: winterOctane.staticVariable
        if ($done) {
            return true;
        }

        $done = true;

        return false;
    }

    /**
     * @return array
     */
    private static function load(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public static function tagged(): array
    {
        /** @octane-worker-scoped Every request stores the same value. */
        static $loaded = null;
        $loaded      ??= self::load();

        return $loaded;
    }
}

/**
 * A project's own worker-scoped attribute. The rule matches it by its short name.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class WorkerScoped
{
    /**
     * @param string $reason
     */
    public function __construct(public string $reason)
    {
    }
}
