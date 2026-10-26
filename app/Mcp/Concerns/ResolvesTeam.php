<?php

namespace App\Mcp\Concerns;

use App\Http\Middleware\EnsureTokenBelongsToCurrentTeamMember;
use App\Models\Server;
use App\Services\Access\AccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait ResolvesTeam
{
    protected function ensureAbility(Request $request, string $ability = 'read', ?string $tool = null): ?Response
    {
        $user = $request->user();
        if (! $user) {
            $this->auditMcpTool($request, $tool, 'denied', ['reason' => 'unauthenticated']);

            return Response::error('Unauthenticated.');
        }

        $token = $user->currentAccessToken();
        if (! $token || $token->team_id === null) {
            $this->auditMcpTool($request, $tool, 'denied', ['reason' => 'invalid_token']);

            return Response::error('Invalid token.');
        }

        // Same check as api.token.team, for tools that run without the HTTP middleware.
        if (! EnsureTokenBelongsToCurrentTeamMember::tokenWithinCreatorAccess($user, $token, (int) $token->team_id)) {
            $this->auditMcpTool($request, $tool, 'denied', [
                'reason' => 'token_exceeds_access',
                'required_ability' => $ability,
            ]);

            return Response::error(EnsureTokenBelongsToCurrentTeamMember::EXCEEDS_ACCESS_MESSAGE);
        }

        if ($token->can('root') || $token->can($ability)) {
            return null;
        }

        $this->auditMcpTool($request, $tool, 'denied', [
            'reason' => 'missing_ability',
            'required_ability' => $ability,
        ]);

        return Response::error("Missing required permissions: {$ability}");
    }

    /**
     * Destructive tools must call this so agents cannot cause damage or downtime without an explicit confirm=true.
     */
    protected function ensureConfirmed(Request $request, string $action): ?Response
    {
        if (filter_var($request->get('confirm'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return $this->mcpError($request, "{$action} requires confirm=true.");
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

    /**
     * Servers of the team, or none for a token or member limited to some projects, environments, or resources.
     *
     * @return Builder<Server>
     */
    protected function visibleServers(Request $request, int $teamId): Builder
    {
        $query = Server::whereTeamId($teamId);
        if (! app(AccessResolver::class)->coversWholeTeam($request->user(), $teamId)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    protected function mcpSuccess(Request $request, Response $response, array $context = []): Response
    {
        $this->auditMcpTool($request, $this->name ?? null, 'success', $context);

        return $response;
    }

    protected function mcpError(Request $request, string $message, array $context = []): Response
    {
        $this->auditMcpTool($request, $this->name ?? null, 'error', $context + ['reason' => $message]);

        return Response::error($message);
    }

    protected function auditMcpTool(Request $request, ?string $tool, string $outcome, array $context = []): void
    {
        auditLog('mcp.tool.called', [
            'tool' => $tool ?: 'unknown',
            'team_id' => $this->resolveTeamId($request),
            'outcome' => $outcome,
            ...$context,
        ]);
    }
}
