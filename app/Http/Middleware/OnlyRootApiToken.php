<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnlyRootApiToken
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

        return response()->json(['message' => 'You are not allowed to perform this action.'], 403);
    }
}
