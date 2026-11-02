<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the deploy canvas call API controllers with the browser session.
 *
 * Gives the session user an in-memory (never saved) API token for the team of
 * the canvas page (the X-Canvas-Team header, set when the page was opened), not
 * the session team, so switching teams in another tab does not change an open
 * canvas. The token has the abilities an API token of that user may have:
 * admins and owners get read, write, deploy and read:sensitive, members get
 * read only. The usual API middleware (api.token.team rejects teams the user is
 * not a member of, api.ability, api.sensitive) and controllers then enforce
 * team scope and permissions as for a real token.
 */
class UseSessionTeamApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamId = $request->header('X-Canvas-Team');

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! is_string($teamId) || ! ctype_digit($teamId)) {
            return response()->json(['message' => 'Missing canvas team.'], 400);
        }
        $teamId = (int) $teamId;

        $abilities = $user->isAdminOfTeam($teamId)
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
