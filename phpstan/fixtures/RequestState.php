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

    public static ?string $skippedByReturn = null;

    public static ?string $restoredFromOverwritten = null;

    public static ?string $skippedByThrow = null;

    public static int $restoredFromIncremented = 0;

    public static array $restoredFromChangedArray = [];

    public static ?string $setBeforeTry = null;

    public static array $resetByTestPlugin = [];

    public static array $resetByTestListener = [];

    public static array $resetInClosure = [];

    public static array $resetByClosureCall = [];

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
     * Called only from a closure that a plugin's resetWorkerState() defines but does not run.
     *
     * @return void
     */
    public static function clearFromClosure(): void
    {
        self::$resetByClosureCall = [];
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
        self::$resetByTestPlugin[]   = $user; // expect: winterOctane.staticPropertyNotReset
        self::$resetByTestListener[] = $user; // expect: winterOctane.staticPropertyNotReset
        self::$resetInClosure[]      = $user; // expect: winterOctane.staticPropertyNotReset
        self::$resetByClosureCall[]  = $user; // expect: winterOctane.staticPropertyNotReset

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
        try {
            self::$restored = true;
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
        $previous = self::$restoredFromSaved;
        try {
            self::$restoredFromSaved = $user;
            $this->handle($user);
        } finally {
            self::$restoredFromSaved = $previous;
        }
    }

    /**
     * Returns before the try block on one path, so the finally block never clears the value.
     *
     * @param string $user
     * @param bool   $skip
     *
     * @return void
     */
    public function withReturnBeforeTry(string $user, bool $skip): void
    {
        self::$skippedByReturn = $user; // expect: winterOctane.staticPropertyNotReset
        if ($skip) {
            return;
        }

        try {
            $this->handle($user);
        } finally {
            self::$skippedByReturn = null;
        }
    }

    /**
     * Calls code between the write and the try block; if it throws, the finally block never runs.
     *
     * @param string $user
     *
     * @return void
     */
    public function withCallBeforeTry(string $user): void
    {
        self::$skippedByThrow = $user; // expect: winterOctane.staticPropertyNotReset
        $this->handle($user);

        try {
            $this->handle($user);
        } finally {
            self::$skippedByThrow = null;
        }
    }

    /**
     * Saves the old array, then changes one of its offsets, so finally does not put the old value back.
     *
     * @param string $user
     *
     * @return void
     */
    public function withChangedSavedArray(string $user): void
    {
        $previous = self::$restoredFromChangedArray;
        try {
            $previous['user']                = $user;
            self::$restoredFromChangedArray = ['temporary' => $user]; // expect: winterOctane.staticPropertyNotReset
        } finally {
            self::$restoredFromChangedArray = $previous;
        }
    }

    /**
     * Sets the value before the try block. Even a plain read in between can throw (an uninitialized typed static), so
     * only writes inside the try block count as protected.
     *
     * @param string $user
     *
     * @return void
     */
    public function withWriteBeforeTry(string $user): void
    {
        self::$setBeforeTry = $user; // expect: winterOctane.staticPropertyNotReset
        try {
            $this->handle($user);
        } finally {
            self::$setBeforeTry = null;
        }
    }

    /**
     * Saves the old value, then increments the saved variable, so finally does not put the old value back.
     *
     * @param int $count
     *
     * @return void
     */
    public function withIncrementedSave(int $count): void
    {
        $previous = self::$restoredFromIncremented;
        try {
            ++$previous;
            self::$restoredFromIncremented = $count; // expect: winterOctane.staticPropertyNotReset
        } finally {
            self::$restoredFromIncremented = $previous;
        }
    }

    /**
     * Saves the old value, then overwrites the saved variable, so finally keeps the request's value.
     *
     * @param string $user
     *
     * @return void
     */
    public function withOverwrittenSave(string $user): void
    {
        $previous = self::$restoredFromOverwritten;
        try {
            $previous                      = $user;
            self::$restoredFromOverwritten = $user; // expect: winterOctane.staticPropertyNotReset
        } finally {
            self::$restoredFromOverwritten = $previous;
        }
    }

    /**
     * Clears the flag in a finally block, then sets it again after the block, where nothing clears it.
     *
     * @return void
     */
    public function withWriteAfterCleanup(): void
    {
        try {
            self::$writtenAfterCleanup = true;
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
        try {
            self::$restoredAtOffset[$name] = true;
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
