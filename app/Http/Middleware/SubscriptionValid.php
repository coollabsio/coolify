<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $is_instance_admin = auth()->user()?->isInstanceAdmin();

        if (!auth()->user() || !is_cloud()) {
            if ($request->path() === 'subscription' &&  !$is_instance_admin) {
                return redirect('/');
            } else {
                return $next($request);
            }
        }
        if (is_subscription_active() && $request->path() === 'subscription' && !$is_instance_admin) {
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
                'logout',
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
