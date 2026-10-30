<?php

namespace App\Http\Middleware;

use App\Services\Access\AccessResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks team-level pages (servers, keys, sources, notifications, team settings, ...) for members
 * whose grants cover only some projects, environments, or resources of the session team.
 */
class EnsureTeamWideAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamId = data_get($user?->currentAccessToken(), 'team_id') ?? $user?->currentTeam()?->id;

        if ($teamId !== null && ! app(AccessResolver::class)->coversWholeTeam($user, $teamId)) {
            abort(403, 'Your access in this team is limited to some projects.');
        }

        return $next($request);
    }
}
