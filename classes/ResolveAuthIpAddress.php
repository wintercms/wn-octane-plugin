<?php namespace Winter\Octane\Classes;

use Closure;
use Illuminate\Http\Request;

/**
 * Records the client address on every Storm auth manager for the current request.
 *
 * Storm's auth manager captures Request::ip() once, when the singleton is constructed, and uses it to
 * scope login throttling. A worker constructs it once, so every later request would be throttled
 * against the first request's address. This middleware runs at the end of the global stack, after
 * the trusted-proxy middleware, so the address it records is the client's rather than the proxy's.
 * It covers the back-end manager and any other subclass held by the container, such as Winter.User's
 * front-end manager, matching the managers ResetsRequestState clears.
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
        /*
         * Under PHP-FPM each auth manager is built for the request and reads the address itself, so
         * this only acts in a worker.
         */
        if (app()->bound('Laravel\Octane\Contracts\Client')
            && class_exists(\Winter\Storm\Auth\Manager::class, false)
            && property_exists(\Illuminate\Container\Container::class, 'instances')
        ) {
            $instances = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instances'))->getValue(app());

            foreach ($instances as $instance) {
                if ($instance instanceof \Winter\Storm\Auth\Manager) {
                    $instance->ipAddress = $request->ip();
                }
            }
        }

        return $next($request);
    }
}
