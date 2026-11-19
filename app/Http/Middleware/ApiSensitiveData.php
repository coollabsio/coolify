<?php

namespace App\Http\Middleware;

use App\Services\Access\AccessResolver;
use Closure;
use Illuminate\Http\Request;

class ApiSensitiveData
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()->currentAccessToken();
        $hasTokenPermission = $token->can('root') || $token->can('read:sensitive');
        $teamId = data_get($token, 'team_id');
        // team_id 0 is the instance-admin team, so a falsy check must not exclude it
        $isAdmin = ! is_null($teamId) ? $request->user()->isAdminOfTeam((int) $teamId) : false;

        $canReadSensitive = $hasTokenPermission && $isAdmin;

        // Server secrets are team-level: a token limited to some projects, environments, or resources
        // can read the secrets of those resources, but not of the servers they run on.
        $request->attributes->add([
            'can_read_sensitive' => $canReadSensitive,
            'can_read_server_sensitive' => $canReadSensitive
                && app(AccessResolver::class)->coversWholeTeam($request->user(), (int) $teamId),
        ]);

        return $next($request);
    }
}
