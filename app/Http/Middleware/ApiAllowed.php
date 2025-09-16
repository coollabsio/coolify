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
        $settings = instanceSettings();
        if ($settings->is_api_enabled === false) {
            return response()->json(['success' => true, 'message' => 'API is disabled.'], 403);
        }

        if ($settings->allowed_ips) {
            // Check for special case: 0.0.0.0 means allow all
            if (trim($settings->allowed_ips) === '0.0.0.0') {
                return $next($request);
            }

            $allowedIps = explode(',', $settings->allowed_ips);
            $allowedIps = array_map('trim', $allowedIps);
            $allowedIps = array_filter($allowedIps); // Remove empty entries

            if (! empty($allowedIps) && ! checkIPAgainstAllowlist($request->ip(), $allowedIps)) {
                return response()->json(['success' => true, 'message' => 'You are not allowed to access the API.'], 403);
            }
        }

        return $next($request);
    }
}
