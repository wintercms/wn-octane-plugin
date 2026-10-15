<?php namespace Winter\Octane\Classes;

use Closure;
use Illuminate\Http\Request;

/**
 * Records the client address on the back-end auth manager for the current request.
 *
 * Storm's auth manager captures Request::ip() once, when the singleton is constructed, and uses it to
 * scope login throttling. A worker constructs it once, so every later request would be throttled
 * against the first request's address. This middleware runs at the end of the global stack, after
 * the trusted-proxy middleware, so the address it records is the client's rather than the proxy's.
 */
class ResolveAuthIpAddress
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (app()->resolved(\Backend\Classes\AuthManager::class)) {
            \Backend\Classes\AuthManager::instance()->ipAddress = $request->ip();
        }

        return $next($request);
    }
}
