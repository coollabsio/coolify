<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamId = $user?->currentAccessToken()?->team_id;

        $team = $user?->teams()
            ->where('teams.id', $teamId)
            ->first();

        if (! $team?->is_mcp_server_enabled) {
            return response()->json(['message' => 'MCP server is disabled for this team.'], 403);
        }

        return $next($request);
    }
}
