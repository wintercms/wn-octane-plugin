<?php namespace Winter\Octane\Classes;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * Serves the files Winter's .htaccess whitelists from the worker, and nothing else.
 *
 * Winter serves from the project root, so the web root of a Swoole or RoadRunner server holds
 * .env, composer.json, the logs, the SQLite database and every PHP file. Both servers serve any
 * file under the web root before the request reaches PHP, and neither can limit that to a list of
 * paths, so the plugin's octane:swoole and octane:roadrunner commands turn their static file
 * serving off. This middleware then serves the whitelisted files instead, the same paths the
 * plugin's Caddyfile serves under FrankenPHP. Every other path continues to the application.
 *
 * It is prepended to the global middleware stack, so a file is returned before sessions, cookies
 * and maintenance mode are involved, as it is when the web server serves it under PHP-FPM.
 */
class ServePublicFiles
{
    /**
     * The paths Winter's .htaccess allows a web server to serve as files, matched against the
     * cleaned request path. The plugin's Caddyfile uses the same expression, and a test keeps the
     * two the same.
     */
    public const WHITELIST = '^/(\.well-known|storage/app/(uploads/public|media|resized)|storage/temp/public)/|^/(themes|plugins|modules)/.+/(assets|resources)/';

    /**
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /*
         * Under PHP-FPM the web server serves these files and the request never reaches PHP, so this
         * only acts in a worker.
         */
        if (!app()->bound('Laravel\Octane\Contracts\Client') || !$request->isMethodCacheable()) {
            return $next($request);
        }

        $file = static::resolve(base_path(), $request->getPathInfo());

        if ($file === null) {
            return $next($request);
        }

        /*
         * Always the whole file with a 200. Octane's Swoole client sends a BinaryFileResponse with
         * sendfile() from its offset to the end of the file, whatever the status and length say, so a
         * 206 for a Range request or a 304 for a revalidation would send the wrong bytes. A full 200
         * is a valid answer to both, and it is what Octane's own static file serving sent.
         */
        $prepareRequest = $request->duplicate();
        $prepareRequest->headers->remove('Range');

        $response = new BinaryFileResponse($file, 200, ['Content-Type' => static::mimeType($file)], true, null, false, true);
        $response->headers->set('Accept-Ranges', 'none');
        $response->prepare($prepareRequest);

        if ($request->isMethod('HEAD')) {
            return new Response('', 200, $response->headers->all());
        }

        return $response;
    }

    /**
     * The file to serve for a request path, or null when the path is not whitelisted or is not a file.
     *
     * The path is decoded and cleaned the way Caddy cleans it before its matchers run, so dot
     * segments cannot climb out of a whitelisted folder: /modules/system/assets/../../../.env is
     * matched as /.env.
     *
     * @param string $basePath
     * @param string $pathInfo The request path, still URL encoded.
     * @return string|null
     */
    public static function resolve(string $basePath, string $pathInfo): ?string
    {
        $decoded = rawurldecode($pathInfo);

        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $path = '/' . implode('/', $segments);

        if (!preg_match('#' . static::WHITELIST . '#', $path) || str_ends_with(strtolower($path), '.php')) {
            return null;
        }

        $file = rtrim($basePath, '/') . $path;

        return is_file($file) ? $file : null;
    }

    /**
     * The content type for a file, from its extension.
     *
     * The extension decides, as it does for a web server: content sniffing reports a stylesheet as
     * text/plain, which browsers refuse to apply.
     *
     * @param string $file
     * @return string
     */
    protected static function mimeType(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream';
    }
}
