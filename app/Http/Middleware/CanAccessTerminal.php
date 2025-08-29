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

        // Only admins/owners can access terminal functionality
        if (! auth()->user()->can('canAccessTerminal')) {
            abort(403, 'Access to terminal functionality is restricted to team administrators');
        }

        return $next($request);
    }
}
