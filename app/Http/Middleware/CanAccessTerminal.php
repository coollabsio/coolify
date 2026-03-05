<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessTerminal
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            abort(401, 'Authentication required');
        }

        $user = auth()->user();
        
        // Check if user is admin/owner using isAdminFromSession which is more reliable
        // Also check isInstanceAdmin for root team access
        if (! $user->isAdminFromSession() && ! $user->isInstanceAdmin()) {
            abort(403, 'Access to terminal functionality is restricted to team administrators');
        }

        return $next($request);
    }
}
