<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait ResolvesTeam
{
    protected function ensureAbility(Request $request, string $ability = 'read'): ?Response
    {
        $user = $request->user();
        if (! $user) {
            return Response::error('Unauthenticated.');
        }

        $token = $user->currentAccessToken();
        if (! $token) {
            return Response::error('Invalid token.');
        }

        if ($token->can('root') || $token->can($ability)) {
            return null;
        }

        return Response::error("Missing required permissions: {$ability}");
    }

    protected function resolveTeamId(Request $request): ?int
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $teamId = $token?->team_id;

        if (! $user || is_null($teamId) || ! $user->teams()->where('teams.id', $teamId)->exists()) {
            return null;
        }

        return (int) $teamId;
    }
}
