<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire SPA navigation (wire:navigate) compares the script tags of the next page
 * with the script tags in the browser. A proxy that rewrites script tags in each
 * response, such as Cloudflare Rocket Loader, makes every script look new, so Livewire,
 * Alpine, and the layout scripts run a second time and the page stays blank.
 * The `no-transform` directive (RFC 9111) tells proxies not to change the HTML.
 */
class PreventHtmlTransform
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        if (str_starts_with((string) $headers->get('Content-Type'), 'text/html') && ! $headers->hasCacheControlDirective('no-transform')) {
            // Append to the current value: addCacheControlDirective() drops the default "no-cache".
            $headers->set('Cache-Control', trim($headers->get('Cache-Control').', no-transform', ', '));
        }

        return $response;
    }
}
