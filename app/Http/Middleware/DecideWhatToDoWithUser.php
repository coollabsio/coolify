<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DecideWhatToDoWithUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()?->user()?->teams?->count() === 0) {
            $currentTeam = auth()->user()?->recreate_personal_team();
            refreshSession($currentTeam);
        }
        if (auth()?->user()?->currentTeam()) {
            refreshSession(auth()->user()->currentTeam());
        }
        if (! auth()->user() || ! isCloud() || isInstanceAdmin()) {
            if (! isCloud() && showBoarding() && ! in_array($request->path(), allowedPathsForBoardingAccounts())) {
                return redirect()->route('onboarding');
            }

            return $next($request);
        }
        if (! auth()->user()->hasVerifiedEmail()) {
            if ($request->path() === 'verify' || in_array($request->path(), allowedPathsForInvalidAccounts()) || $request->routeIs('verify.verify')) {
                return $next($request);
            }

            return redirect()->route('verify.email');
        }
        if (! isSubscriptionActive() && ! isSubscriptionOnGracePeriod()) {
            if (! in_array($request->path(), allowedPathsForUnsubscribedAccounts())) {
                if (Str::startsWith($request->path(), 'invitations')) {
                    return $next($request);
                }

                return redirect()->route('subscription.index');
            }
        }
        if (showBoarding() && ! in_array($request->path(), allowedPathsForBoardingAccounts())) {
            if (Str::startsWith($request->path(), 'invitations')) {
                return $next($request);
            }

            return redirect()->route('onboarding');
        }
        if (auth()->user()->hasVerifiedEmail() && $request->path() === 'verify') {
            return redirect(RouteServiceProvider::HOME);
        }
        if (isSubscriptionActive() && $request->routeIs('subscription.index')) {
            return redirect(RouteServiceProvider::HOME);
        }

        return $next($request);
    }
}
