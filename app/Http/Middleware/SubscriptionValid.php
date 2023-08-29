<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionValid
{
    public function handle(Request $request, Closure $next): Response
    {
        ray()->showQueries()->color('orange');
        if (isInstanceAdmin()) {
            return $next($request);
        }
        if (!auth()->user() || !is_cloud()) {
            if ($request->path() === 'subscription') {
                return redirect('/');
            } else {
                return $next($request);
            }
        }
        if (isSubscriptionActive() && $request->path() === 'subscription') {
            // ray('active subscription Middleware');
            return redirect('/');
        }
        if (isSubscriptionOnGracePeriod()) {
            // ray('is_subscription_in_grace_period Middleware');
            return $next($request);
        }
        if (!isSubscriptionActive() && !isSubscriptionOnGracePeriod()) {
            // ray('SubscriptionValid Middleware');
            if (!in_array($request->path(), allowedPathsForUnsubscribedAccounts())) {
                return redirect('subscription');
            } else {
                return $next($request);
            }
        }
        return $next($request);
    }
}
