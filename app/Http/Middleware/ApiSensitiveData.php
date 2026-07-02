<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiSensitiveData
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()->currentAccessToken();
        $hasTokenPermission = $token->can('root') || $token->can('read:sensitive');
        $teamId = (int) data_get($token, 'team_id');
        $isAdmin = $teamId ? $request->user()->isAdminOfTeam($teamId) : false;

        // Allow access to sensitive data only if token has permission AND user is admin/owner
        $request->attributes->add([
            'can_read_sensitive' => $hasTokenPermission && $isAdmin,
        ]);

        return $next($request);
    }
}
