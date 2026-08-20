<?php

namespace App\Http\Middleware\V5;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $currentTeam = $this->resolveCurrentTeam($user);

        if (! $currentTeam) {
            abort(403, 'No team available for this user.');
        }

        // The v4 UI stores a full Team model under the same session key and
        // reads arbitrary columns off it, so only rewrite the session when the
        // resolved team actually changed — and always store the full model.
        if (data_get(session('currentTeam'), 'id') !== $currentTeam->id) {
            session(['currentTeam' => $currentTeam]);
        }

        $request->attributes->set('v5.currentTeam', $currentTeam);

        return $next($request);
    }

    private function resolveCurrentTeam(User $user): ?Team
    {
        $sessionTeamId = data_get(session('currentTeam'), 'id');

        if ($sessionTeamId) {
            $sessionTeam = $user->teams()
                ->whereKey($sessionTeamId)
                ->first();

            if ($sessionTeam) {
                return $sessionTeam;
            }
        }

        return $user->teams()
            ->orderBy('teams.id')
            ->first();
    }
}
