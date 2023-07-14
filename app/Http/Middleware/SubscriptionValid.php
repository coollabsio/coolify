<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionValid
{

    public function handle(Request $request, Closure $next): Response
    {
        $allowed_paths = [
            'subscription',
            'login',
            'register',
            'logout',
            'livewire/message/check-license',
            'livewire/message/switch-team',
        ];
        if (isCloud() && !isSubscribed()) {
            ray('SubscriptionValid Middleware');
            if (!in_array($request->path(), $allowed_paths)) {
                return redirect('subscription');
            } else {
                return $next($request);
            }
        } else {
            if ($request->path() === 'subscription') {
                return redirect('/');
            } else {
                return $next($request);
            }
        }
    }
}
