<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (isCloud()) {
            return $next($request);
        }
        if (instanceSettings()->is_api_enabled === false) {
            return response()->json(['success' => true, 'message' => 'API is disabled.'], 403);
        }

        return app(ApiIpAllowed::class)->handle($request, $next);
    }
}
