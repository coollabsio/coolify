<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTeamTwoFactor
{
    /**
     * Send users without confirmed 2FA to the setup page while their current team requires it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || filled($user->two_factor_confirmed_at)) {
            return $next($request);
        }

        $teamId = data_get(session('currentTeam'), 'id');
        if (is_null($teamId) || in_array($request->path(), allowedPathsForTwoFactorSetup(), true)) {
            return $next($request);
        }

        // Read the flag from the database: the session and cache can hold a team from before the change.
        if (! Team::query()->whereKey($teamId)->value('is_two_factor_required')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->routeIs('*livewire.update')) {
            abort(403, 'Your team requires two-factor authentication. Configure it to continue.');
        }

        return redirect()->route('two-factor.required');
    }
}
