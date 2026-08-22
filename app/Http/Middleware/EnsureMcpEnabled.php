<?php

namespace App\Http\Middleware;

use App\Models\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! InstanceSettings::get()->is_mcp_server_enabled) {
            abort(404);
        }

        return $next($request);
    }
}
