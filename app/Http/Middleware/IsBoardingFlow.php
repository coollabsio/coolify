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
        $allowed_paths = [
            'subscription',
            'boarding',
            'livewire/message/boarding'
        ];
        if (showBoarding() && !in_array($request->path(), $allowed_paths)) {
            return redirect('boarding');
        }
        return $next($request);
    }
}
