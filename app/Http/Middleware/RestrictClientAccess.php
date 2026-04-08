<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks scoped client users from accessing routes that are reserved for
 * full team members. Clients still hit the global RestrictsToClientProjects
 * scope on Project/Application/etc., but this middleware adds a second layer
 * for non-resource pages (Servers, Sources, Settings, Users management, ...).
 */
class RestrictClientAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            abort(401, 'Authentication required');
        }

        if (auth()->user()->isClient()) {
            abort(403, 'This area is restricted to team administrators.');
        }

        return $next($request);
    }
}
