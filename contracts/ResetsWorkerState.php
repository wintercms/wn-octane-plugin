<?php namespace Winter\Octane\Contracts;

/**
 * Implemented by plugins that keep state derived from a single request.
 *
 * Under Laravel Octane the same plugin object serves many requests. resetWorkerState() is called at
 * the start of every Octane operation so the plugin can discard what the previous one produced.
 *
 * - It may be called more than once for a single operation, so it must be safe to repeat.
 * - Clear only what a request produced. Registration callbacks, navigation definitions and event
 *   listeners are built once per worker and are not rebuilt if discarded.
 *
 * Implementing the interface is optional: the reset also calls a plain resetWorkerState() method.
 */
interface ResetsWorkerState
{
    /**
     * Discard state derived from the previous operation.
     */
    public function resetWorkerState(): void;
}
