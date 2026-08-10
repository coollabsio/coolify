<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamTerminalApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamId = $user?->currentAccessToken()?->team_id;

        $team = $user?->teams()
            ->where('teams.id', $teamId)
            ->first();

        if (! $team?->is_terminal_api_enabled) {
            return response()->json(['message' => 'Terminal API is disabled for this team.'], 403);
        }

        return $next($request);
    }
}
