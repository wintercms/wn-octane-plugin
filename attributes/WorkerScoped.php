<?php

namespace Winter\Octane\Attributes;

use Attribute;

/**
 * Marks a static property whose value may outlive the request that set it.
 *
 * Under Octane one worker serves many requests, and a static property keeps its value between them. The
 * winterOctane.staticPropertyNotReset PHPStan rule (phpstan/extension.neon) reports a static that request code
 * writes and nothing clears. Put this attribute on a property where keeping the value for the worker's life is
 * safe, and say why. It has no effect at runtime.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class WorkerScoped
{
    /**
     * @param string $reason Why keeping the value for the worker's life is safe.
     */
    public function __construct(public string $reason)
    {
    }
}
