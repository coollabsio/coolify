<?php

namespace App\Mcp\Concerns;

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
        if (! $token) {
            $this->auditMcpTool($request, $tool, 'denied', ['reason' => 'invalid_token']);

            return Response::error('Invalid token.');
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
