<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * After the proxy headers are applied, an HTTPS request gets Secure cookies when
     * SESSION_SECURE_COOKIE is not set. A plain HTTP request (for example on port 8000)
     * then gets its own session and XSRF cookie names: a browser does not let plain HTTP
     * overwrite the Secure cookies of an HTTPS visit to the same host, so shared names
     * would break the login with "419 Page Expired" (#4313).
     */
    public function handle($request, \Closure $next)
    {
        return parent::handle($request, function ($request) use ($next) {
            if (config('session.secure') === null) {
                if ($request->secure()) {
                    config(['session.secure' => true]);
                } else {
                    $sessionCookie = Str::finish(config('session.cookie'), '_http');
                    config(['session.cookie' => $sessionCookie, 'session.xsrf_cookie' => 'XSRF-TOKEN-HTTP']);
                    // The session store can exist already, with the name from the config.
                    app('session.store')->setName($sessionCookie);
                }
            }

            return $next($request);
        });
    }
}
