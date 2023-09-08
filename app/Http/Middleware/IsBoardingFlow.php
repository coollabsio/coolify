<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsBoardingFlow
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        ray()->showQueries()->color('orange');
        if (showBoarding() && !in_array($request->path(), allowedPathsForBoardingAccounts())) {
            return redirect('boarding');
        }
        return $next($request);
    }
}
