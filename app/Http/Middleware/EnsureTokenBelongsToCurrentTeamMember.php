<?php

namespace App\Http\Middleware;

use App\Enums\AccessLevel;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenBelongsToCurrentTeamMember
{
    public const EXCEEDS_ACCESS_MESSAGE = 'This API token has more access than you have in this team. Revoke it and create a new token.';

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

        if (! self::tokenWithinCreatorAccess($user, $token, (int) $teamId)) {
            auditLog('api.auth.token_exceeds_access', [
                'token_id' => $token->getKey(),
                'team_id' => (int) $teamId,
                'abilities' => $token->abilities ?? [],
            ], 'warning');

            // MCP clients expect JSON-RPC envelopes (often only parsed on HTTP 200).
            // Keep REST API clients on plain 403 JSON.
            if ($request->is('mcp') || $request->is('mcp/*')) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $request->input('id'),
                    'error' => [
                        'code' => -32003,
                        'message' => self::EXCEEDS_ACCESS_MESSAGE,
                    ],
                ]);
            }

            return response()->json(['message' => self::EXCEEDS_ACCESS_MESSAGE], 403);
        }

        return $next($request);
    }

    /**
     * A token can never do more than its owner can do in the token team, also after the owner lost access.
     * Owners and admins hold every level. A member must hold every token ability on every token scope.
     */
    public static function tokenWithinCreatorAccess(User $user, SanctumPersonalAccessToken $token, int $teamId): bool
    {
        if ($user->isAdminOfTeam($teamId)) {
            return true;
        }

        // The deploy canvas token is built in memory from the session user's role, never from user input.
        if (! $token->exists) {
            return true;
        }

        $abilities = array_values($token->abilities ?? []);
        $levels = AccessLevel::parseMany($abilities);
        // Abilities outside the access levels (write:sensitive, *) are for owners and admins only.
        if (count($levels) !== count(array_unique($abilities))) {
            return false;
        }

        $grants = $token instanceof PersonalAccessToken ? AccessResolver::tokenGrants($token) : null;

        return app(AccessResolver::class)->userHolds($user, $teamId, $grants ?? [Grant::team($levels)]);
    }
}
