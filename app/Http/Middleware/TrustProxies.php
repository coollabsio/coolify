<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Handle the request.
     *
     * Wraps $next so that after proxy headers are resolved (X-Forwarded-Proto processed),
     * the Secure cookie flag is auto-enabled when the request is over HTTPS.
     * This ensures session cookies are correctly marked Secure when behind an HTTPS
     * reverse proxy (Cloudflare Tunnel, nginx, etc.) even when SESSION_SECURE_COOKIE
     * is not explicitly set in .env.
     */
    public function handle($request, \Closure $next)
    {
        return parent::handle($request, function ($request) use ($next) {
            // At this point proxy headers have been applied to the request,
            // so $request->secure() correctly reflects the actual protocol.
            if ($request->secure() && config('session.secure') === null) {
                config(['session.secure' => true]);
            }

            return $next($request);
        });
    }
}
