<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        ray()->clearAll();
        if (isCloud()) {
            return $next($request);
        }
        $settings = \App\Models\InstanceSettings::get();
        if ($settings->is_api_enabled === false) {
            return response()->json(['success' => true, 'message' => 'API is disabled.'], 403);
        }

        if (! isDev()) {
            if ($settings->allowed_ips) {
                $allowedIps = explode(',', $settings->allowed_ips);
                if (! in_array($request->ip(), $allowedIps)) {
                    return response()->json(['success' => true, 'message' => 'You are not allowed to access the API.'], 403);
                }
            }
        }

        return $next($request);
    }
}
