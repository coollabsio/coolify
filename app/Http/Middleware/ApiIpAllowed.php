<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (isCloud()) {
            return $next($request);
        }

        $allowedIps = trim((string) instanceSettings()->allowed_ips);
        // Empty or 0.0.0.0 means allow all
        if ($allowedIps === '' || $allowedIps === '0.0.0.0') {
            return $next($request);
        }

        $allowedIps = array_filter(array_map('trim', explode(',', $allowedIps)));

        if (! empty($allowedIps) && ! checkIPAgainstAllowlist(client_ip($request), $allowedIps)) {
            return response()->json(['success' => true, 'message' => 'You are not allowed to access the API.'], 403);
        }

        return $next($request);
    }
}
