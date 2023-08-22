<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionValid
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->user() || !is_cloud()) {
            if ($request->path() === 'subscription') {
                return redirect('/');
            } else {
                return $next($request);
            }
        }
        if (isInstanceAdmin()) {
            return $next($request);
        }

        if (is_subscription_active() && $request->path() === 'subscription') {
            return redirect('/');
        }
        if (is_subscription_in_grace_period()) {
            return $next($request);
        }
        if (!is_subscription_active() && !is_subscription_in_grace_period()) {
            ray('SubscriptionValid Middleware');

            $allowed_paths = [
                'subscription',
                'login',
                'register',
                'waitlist',
                'force-password-reset',
                'logout',
                'livewire/message/force-password-reset',
                'livewire/message/check-license',
                'livewire/message/switch-team',
            ];
            if (!in_array($request->path(), $allowed_paths)) {
                return redirect('subscription');
            } else {
                return $next($request);
            }
        }
        return $next($request);
    }
}
