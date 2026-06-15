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

        session(['currentTeam' => $currentTeam]);
        $request->attributes->set('v5.currentTeam', $currentTeam);

        return $next($request);
    }

    private function resolveCurrentTeam(User $user): ?Team
    {
        $sessionTeamId = data_get(session('currentTeam'), 'id');

        if ($sessionTeamId) {
            $sessionTeam = $user->teams()
                ->select('teams.id', 'teams.name', 'teams.description', 'teams.personal_team')
                ->whereKey($sessionTeamId)
                ->first();

            if ($sessionTeam) {
                return $sessionTeam;
            }
        }

        return $user->teams()
            ->select('teams.id', 'teams.name', 'teams.description', 'teams.personal_team')
            ->orderBy('teams.id')
            ->first();
    }
}
