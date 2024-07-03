<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IgnoreReadOnlyApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = auth()->user()->currentAccessToken();
        if ($token->can('*')) {
            return $next($request);
        }
        if ($token->can('read-only')) {
            return response()->json(['message' => 'You are not allowed to perform this action.'], 403);
        }

        return $next($request);
    }
}
