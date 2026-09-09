<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait ResolvesTeam
{
    /**
     * Abilities that team members must not exercise (parity with ApiAbility).
     *
     * @var array<int, string>
     */
    private const MEMBER_DISALLOWED_ABILITIES = [
        'root',
        'write',
        'write:sensitive',
        'deploy',
        'read:sensitive',
    ];

    protected function ensureAbility(Request $request, string $ability = 'read', ?string $tool = null): ?Response
    {
        $user = $request->user() ?? auth()->user();
        if (! $user) {
            $this->auditMcpTool($request, $tool, 'denied', ['reason' => 'unauthenticated']);

            return Response::error('Unauthenticated.');
        }

        $token = $user->currentAccessToken();

        if ($token) {
            // --- Token path (external MCP client): unchanged behavior. ---
            $teamId = $token->team_id;
            if ($teamId !== null) {
                // Fresh pivot lookup (avoid stale $user->teams cache after role changes).
                $role = $user->teams()->where('teams.id', $teamId)->first()?->pivot?->role;
                $isAdminOrOwner = in_array($role, ['admin', 'owner'], true);

                if (! $isAdminOrOwner) {
                    $tokenAbilities = $token->abilities ?? [];
                    $disallowed = array_intersect($tokenAbilities, self::MEMBER_DISALLOWED_ABILITIES);
                    if ($disallowed !== [] || in_array($ability, self::MEMBER_DISALLOWED_ABILITIES, true)) {
                        $this->auditMcpTool($request, $tool, 'denied', [
                            'reason' => 'member_role',
                            'required_ability' => $ability,
                        ]);

                        return Response::error('Missing required team role.');
                    }
                }
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

        // --- Session path (in-app agent, no token): authorize by team role. ---
        $teamId = currentTeam()?->id;
        if (is_null($teamId) || ! $user->teams()->where('teams.id', $teamId)->exists()) {
            $this->auditMcpTool($request, $tool, 'denied', ['reason' => 'no_team']);

            return Response::error('No accessible team.');
        }

        if ($ability === 'read') {
            return null;
        }

        $role = $user->teams()->where('teams.id', $teamId)->first()?->pivot?->role;
        if (in_array($role, ['admin', 'owner'], true)) {
            return null;
        }

        $this->auditMcpTool($request, $tool, 'denied', [
            'reason' => 'member_role',
            'required_ability' => $ability,
        ]);

        return Response::error('Missing required team role.');
    }

    protected function resolveTeamId(Request $request): ?int
    {
        $user = $request->user() ?? auth()->user();
        if (! $user) {
            return null;
        }

        $token = $user->currentAccessToken();

        // Token path (external MCP): team comes from the token.
        $teamId = $token?->team_id;

        // Session path (in-app agent, no token): fall back to the current team.
        if (is_null($teamId) && is_null($token)) {
            $teamId = currentTeam()?->id;
        }

        if (is_null($teamId) || ! $user->teams()->where('teams.id', $teamId)->exists()) {
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
