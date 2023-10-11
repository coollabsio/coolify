<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class DecideWhatToDoWithUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->user() || !isCloud() || isInstanceAdmin()) {
            if (!isCloud() && showBoarding()  && !in_array($request->path(), allowedPathsForBoardingAccounts())) {
                return redirect('boarding');
            }
            return $next($request);
        }
        if (!auth()->user()->hasVerifiedEmail()) {
            if ($request->path() === 'verify' || in_array($request->path(), allowedPathsForInvalidAccounts()) || $request->routeIs('verify.verify')) {
                return $next($request);
            }
            return redirect('/verify');
        }
        if (!isSubscriptionActive() && !isSubscriptionOnGracePeriod()) {
            if (!in_array($request->path(), allowedPathsForUnsubscribedAccounts())) {
                if (Str::startsWith($request->path(), 'invitations')) {
                    return $next($request);
                }
                return redirect('subscription');
            }
        }
        if (showBoarding() && !in_array($request->path(), allowedPathsForBoardingAccounts())) {
            if (Str::startsWith($request->path(), 'invitations')) {
                return $next($request);
            }
            return redirect('boarding');
        }
        if (auth()->user()->hasVerifiedEmail() && $request->path() === 'verify') {
            return redirect('/');
        }
        if (isSubscriptionActive() && $request->path() === 'subscription') {
            return redirect('/');
        }
        return $next($request);
    }
}
