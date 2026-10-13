<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the deploy canvas call API controllers with the browser session.
 *
 * Gives the session user an in-memory (never saved) API token for the session
 * team, with the abilities an API token of that user may have: admins and
 * owners get read, write, deploy and read:sensitive, members get read only.
 * The usual API middleware (api.token.team, api.ability, api.sensitive) and
 * controllers then enforce team scope and permissions as for a real token.
 */
class UseSessionTeamApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamId = $user?->currentTeam()?->id;

        if (! $user || is_null($teamId)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $abilities = $user->isAdminOfTeam((int) $teamId)
            ? ['read', 'write', 'deploy', 'read:sensitive']
            : ['read'];

        $token = new PersonalAccessToken;
        $token->forceFill([
            'name' => 'deploy-canvas-session',
            'team_id' => $teamId,
            'abilities' => $abilities,
        ]);
        $user->withAccessToken($token);

        return $next($request);
    }
}
