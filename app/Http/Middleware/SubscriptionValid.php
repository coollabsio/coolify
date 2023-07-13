<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed_paths = [
            'team',
            'livewire/message/team',
            'login',
            'register',
            'livewire/message/switch-team',
            'logout',
        ];
        if (isCloud()) {
           if (!$request->user()?->currentTeam()?->subscription && $request->user()?->currentTeam()->subscription?->lemon_status !== 'active') {
            if (!in_array($request->path(), $allowed_paths)) {
                return redirect('team');
            }
            }
        }
        return $next($request);
    }
}