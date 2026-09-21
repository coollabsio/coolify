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
            // A team is already active; the selection screen no longer applies.
            if ($request->routeIs('team.select')) {
                return redirect()->route('dashboard');
            }
        } elseif (auth()?->user()?->teams?->count() > 0) {
            // No active team in the session (fresh login or invalidated selection).
            // Restore the last active team, or the sole team of a single-team user.
            $resolvedTeam = auth()->user()->resolveStoredTeam();
            if ($resolvedTeam) {
                refreshSession($resolvedTeam);
            } elseif ($request->routeIs('team.select') || $request->routeIs('*livewire.update')) {
                // Ambiguous choice: let the user pick a team on the selection screen.
                // Livewire's update endpoint must pass through too, otherwise the
                // selection action's AJAX call is redirected to HTML and never runs.
                return $next($request);
            } else {
                return redirect()->route('team.select');
            }
        }
        if (! auth()->user() || ! isCloud()) {
            if (! isCloud() && showBoarding() && ! in_array($request->path(), allowedPathsForBoardingAccounts())) {
                return redirect()->route('onboarding');
            }

            return $next($request);
        }
        // Instance admins can access settings and admin routes regardless of subscription
        if (isInstanceAdmin() && ($request->routeIs('settings.*') || $request->path() === 'admin')) {
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
