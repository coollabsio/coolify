<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenBelongsToCurrentTeamMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $teamId = $token?->team_id;

        if (! $user || ! $token || is_null($teamId)) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $team = $user->teams()
            ->where('teams.id', $teamId)
            ->first();

        if (! $team) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $role = $team->pivot?->role;
        // Match ApiAbility::MEMBER_DISALLOWED_ABILITIES — members are read-only.
        $elevated = $token->can('root')
            || $token->can('write')
            || $token->can('write:sensitive')
            || $token->can('deploy')
            || $token->can('read:sensitive');

        if ($elevated && ! in_array($role, ['admin', 'owner'], true)) {
            // MCP clients expect JSON-RPC envelopes (often only parsed on HTTP 200).
            // Keep REST API clients on plain 403 JSON.
            if ($request->is('mcp') || $request->is('mcp/*')) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $request->input('id'),
                    'error' => [
                        'code' => -32003,
                        'message' => 'Missing required team role.',
                    ],
                ]);
            }

            return response()->json(['message' => 'Missing required team role.'], 403);
        }

        return $next($request);
    }
}
