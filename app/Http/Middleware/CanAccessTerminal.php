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
        $isAdminFromSession = $user->isAdminFromSession();
        $isInstanceAdmin = $user->isInstanceAdmin();
        
        // Debug logging (remove in production)
        \Log::info('CanAccessTerminal middleware check', [
            'user_id' => $user->id,
            'isAdminFromSession' => $isAdminFromSession,
            'isInstanceAdmin' => $isInstanceAdmin,
            'route' => $request->route()->getName(),
        ]);
        
        if (! $isAdminFromSession && ! $isInstanceAdmin) {
            abort(403, 'Access to terminal functionality is restricted to team administrators. isAdminFromSession: '.($isAdminFromSession ? 'true' : 'false').', isInstanceAdmin: '.($isInstanceAdmin ? 'true' : 'false'));
        }

        return $next($request);
    }
}
