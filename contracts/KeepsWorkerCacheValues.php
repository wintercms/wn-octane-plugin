<?php namespace Winter\Octane\Contracts;

/**
 * Implemented by plugins that keep chosen in-memory cache values from one operation to the next.
 *
 * At the start of every Octane operation the reset empties the in-memory layer of each Halcyon
 * MemoryRepository store, so every value is read from the external store again. A value for which
 * keepWorkerCacheValue() returns true is kept instead, as long as it holds only nulls, scalars and
 * arrays of them.
 *
 * - The plugin is responsible for dropping a kept value when the external store changes, for
 *   example by returning false once its own record of the value's version is out of date.
 * - It is called for every key in memory before resetWorkerState(), and before the incoming
 *   request is bound, so it must be quick and must not read the current request.
 *
 * Implementing the interface is optional: the reset also calls a plain keepWorkerCacheValue() method.
 */
interface KeepsWorkerCacheValues
{
    /**
     * Whether the in-memory value of a cache key survives into the next operation.
     *
     * @param string $key The cache key, without the store's prefix.
     * @param mixed $value The value held in memory.
     */
    public function keepWorkerCacheValue(string $key, $value): bool;
}
