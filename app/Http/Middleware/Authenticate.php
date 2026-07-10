<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Authenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $guard = null)
    {
        if (Auth::guard($guard)->guest()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response('Unauthorized.', 401);
            } else {
                return redirect()->guest('login');
            }
        }

        // Check if the user is logging in via OAuth2
        if (Auth::user()->provider === 'oauth2') {
            // Allow self-registration for OAuth2 users
            return $next($request);
        }

        // Check if self-registration is enabled
        if (!config('auth.self_registration')) {
            return redirect()->back()->with('error', 'Self-registration is not allowed.');
        }

        return $next($request);
    }
}
