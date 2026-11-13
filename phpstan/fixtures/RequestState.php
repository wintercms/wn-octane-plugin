<?php

namespace Winter\Octane\PHPStan\Fixtures;

use ReflectionProperty;
use WeakMap;

/**
 * Static properties that request code writes. A trailing "expect:" comment marks each line the rules must report;
 * every other write must stay silent.
 */
class RequestState
{
    public static array $clearedAtRequestStart = [];

    public static array $clearedAtTerminate = [];

    public static array $clearedByReflection = [];

    public static array $clearedByReset = [];

    public static array $clearedTwoCallsDeep = [];

    public static array $clearedByNonPlugin = [];

    public static array $clearedByPluginWithoutCalls = [];

    public static array $filledByReset = [];

    public static array $partlyRestored = [];

    public static array $partlyReset = [];

    public static ?string $keptByFinally = null;

    public static ?string $restoredFromSaved = null;

    public static bool $writtenAfterCleanup = false;

    public static array $restoredAtOffset = [];

    public static bool $flag = false;

    public static ?ReflectionProperty $memo = null;

    public static bool $restored = false;

    public static array $setAtBoot = [];

    public static array $setBySetterFromBoot = [];

    public static array $setInBootClosure = [];

    public static array $setInCommand = [];

    #[WorkerScoped('Every request stores the same value.')]
    public static array $tagged = [];

    /**
     * @octane-worker-scoped Every request stores the same value.
     */
    public static array $taggedInDocblock = [];

    #[\Winter\Octane\Attributes\WorkerScoped('Every request stores the same value.')]
    public static array $taggedWithPluginAttribute = [];

    public static array $users = [];

    public static ?WeakMap $weak = null;

    /**
     * Fills a static. Reset code calls it too, but that does not make it a reset.
     *
     * @param string $value
     *
     * @return void
     */
    public static function addFilled(string $value): void
    {
        self::$filledByReset[] = $value; // expect: winterOctane.staticPropertyNotReset
    }

    /**
     * Called only from a plugin's boot().
     *
     * @param string $value
     *
     * @return void
     */
    public static function addAtBoot(string $value): void
    {
        self::$setBySetterFromBoot[] = $value;
    }

    /**
     * Called at the start of every request by an Octane RequestReceived listener.
     *
     * @return void
     */
    public static function beginRequest(): void
    {
        self::$clearedAtRequestStart = [];
    }

    /**
     * The second call away from the terminating callback.
     *
     * @return void
     */
    public static function flushInner(): void
    {
        self::$clearedTwoCallsDeep = [];
    }

    /**
     * Called by a terminating callback; clears state two calls away from it, which the rule does not follow.
     *
     * @return void
     */
    public static function flushOuter(): void
    {
        self::flushInner();
    }

    /**
     * Records state the way request code does.
     *
     * @param string $user
     *
     * @return void
     */
    public function handle(string $user): void
    {
        self::$users[] = $user; // expect: winterOctane.staticPropertyNotReset
        self::$flag    = true; // expect: winterOctane.staticPropertyNotReset
        self::$memo  ??= new ReflectionProperty(self::class, 'users');
        self::$weak  ??= new WeakMap();

        self::$tagged[]                = $user;
        self::$taggedInDocblock[]      = $user;
        self::$taggedWithPluginAttribute[] = $user;
        self::$clearedByReset[]        = $user;
        self::$clearedByReflection[]   = $user;
        self::$clearedAtTerminate[]    = $user;
        self::$clearedAtRequestStart[] = $user;
        self::$clearedTwoCallsDeep[]   = $user; // expect: winterOctane.staticPropertyNotReset
        self::$clearedByNonPlugin[]    = $user; // expect: winterOctane.staticPropertyNotReset
        self::$clearedByPluginWithoutCalls[] = $user;
        self::addFilled($user);
        self::$partlyReset[$user] = $user; // expect: winterOctane.staticPropertyNotReset

        // Winter.Octane clears this one itself (ResetsRequestState::STATIC_CACHES).
        \System\Models\Parameter::$cache[$user] = $user;
    }

    /**
     * Sets a static for the length of a call and puts it back in finally.
     *
     * @return void
     */
    public function withRestore(): void
    {
        self::$restored = true;
        try {
            $this->handle('restore');
        } finally {
            self::$restored = false;
        }
    }

    /**
     * Assigns the request's value again in finally, which keeps it instead of restoring anything.
     *
     * @param string $user
     *
     * @return void
     */
    public function withFinallyThatKeepsTheValue(string $user): void
    {
        try {
            self::$keptByFinally = $user; // expect: winterOctane.staticPropertyNotReset
        } finally {
            self::$keptByFinally = $user;
        }
    }

    /**
     * Saves the old value and puts it back in finally.
     *
     * @param string $user
     *
     * @return void
     */
    public function withSavedRestore(string $user): void
    {
        $previous                = self::$restoredFromSaved;
        self::$restoredFromSaved = $user;
        try {
            $this->handle($user);
        } finally {
            self::$restoredFromSaved = $previous;
        }
    }

    /**
     * Clears the flag in a finally block, then sets it again after the block, where nothing clears it.
     *
     * @return void
     */
    public function withWriteAfterCleanup(): void
    {
        self::$writtenAfterCleanup = true;
        try {
            $this->handle('cleanup');
        } finally {
            self::$writtenAfterCleanup = false;
        }

        self::$writtenAfterCleanup = true; // expect: winterOctane.staticPropertyNotReset
    }

    /**
     * Fills one offset and removes the same one in finally.
     *
     * @param string $name
     *
     * @return void
     */
    public function withOffsetRestore(string $name): void
    {
        self::$restoredAtOffset[$name] = true;
        try {
            $this->handle($name);
        } finally {
            unset(self::$restoredAtOffset[$name]);
        }
    }

    /**
     * Fills one offset and only removes a different one in finally, which restores nothing.
     *
     * @param string $a
     * @param string $b
     *
     * @return void
     */
    public function withPartialRestore(string $a, string $b): void
    {
        self::$partlyRestored[$a] = $a; // expect: winterOctane.staticPropertyNotReset
        try {
            $this->handle($a);
        } finally {
            unset(self::$partlyRestored[$b]);
        }
    }
}
