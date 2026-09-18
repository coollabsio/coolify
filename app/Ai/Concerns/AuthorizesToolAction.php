<?php

namespace App\Ai\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Shared safety spine for native AI write tools: resolve the acting user +
 * team, authorize the target through its existing Coolify policy, and audit.
 * The agent never exceeds the acting user's real permissions.
 */
trait AuthorizesToolAction
{
    protected function actingUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('The assistant has no authenticated user.');
        }

        return $user;
    }

    protected function actingTeamId(): int
    {
        $teamId = currentTeam()?->id;

        if (is_null($teamId)) {
            throw new AuthorizationException('The assistant has no active team.');
        }

        return (int) $teamId;
    }

    /**
     * Authorize the acting user against the target's policy, or throw.
     */
    protected function authorizeToolAction(string $ability, Model $target, string $tool): User
    {
        $user = $this->actingUser();

        try {
            Gate::forUser($user)->authorize($ability, $target);
        } catch (AuthorizationException $e) {
            $this->auditToolCall($tool, 'denied', [
                'ability' => $ability,
                'target' => $target::class,
                'target_id' => $target->getKey(),
            ]);

            throw $e;
        }

        return $user;
    }

    /**
     * Authorize the acting user against a gate ability that takes no target
     * model (e.g. createAnyResource), or throw. Audits denials like
     * authorizeToolAction.
     */
    protected function authorizeToolGate(string $ability, string $tool): User
    {
        $user = $this->actingUser();

        try {
            Gate::forUser($user)->authorize($ability);
        } catch (AuthorizationException $e) {
            $this->auditToolCall($tool, 'denied', ['ability' => $ability]);

            throw $e;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function auditToolCall(string $tool, string $outcome, array $context = []): void
    {
        auditLog('ai.tool.called', [
            'tool' => $tool,
            'team_id' => currentTeam()?->id,
            'user_id' => auth()->id(),
            'outcome' => $outcome,
            ...$context,
        ]);
    }
}
