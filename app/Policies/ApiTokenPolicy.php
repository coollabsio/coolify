<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenPolicy
{
    /**
     * Determine whether the user can view any API tokens.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the API token.
     */
    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $this->belongsToUserAndCurrentTeam($user, $token);
    }

    /**
     * Determine whether the user can create API tokens.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the API token.
     */
    public function update(User $user, PersonalAccessToken $token): bool
    {
        return $this->belongsToUserAndCurrentTeam($user, $token);
    }

    /**
     * Determine whether the user can delete the API token.
     */
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $this->belongsToUserAndCurrentTeam($user, $token);
    }

    /**
     * Determine whether the user can manage their own API tokens.
     */
    public function manage(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can use root permissions for API tokens.
     */
    public function useRootPermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner() || $this->holdsSomewhere($user, AccessLevel::Root);
    }

    /**
     * Determine whether the user can use write permissions for API tokens.
     */
    public function useWritePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner() || $this->holdsSomewhere($user, AccessLevel::Write);
    }

    /**
     * Determine whether the user can use deploy permissions for API tokens.
     */
    public function useDeployPermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner() || $this->holdsSomewhere($user, AccessLevel::Deploy);
    }

    /**
     * Determine whether the user can use read:sensitive permissions for API tokens.
     */
    public function useSensitivePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner() || $this->holdsSomewhere($user, AccessLevel::ReadSensitive);
    }

    /**
     * Determine whether the user can use delete permissions for API tokens.
     */
    public function useDeletePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner() || $this->holdsSomewhere($user, AccessLevel::Delete);
    }

    /**
     * True when a grant of the member in the current team gives the level somewhere.
     * Creating the token still checks that the member holds it on every chosen scope.
     */
    private function holdsSomewhere(User $user, AccessLevel $level): bool
    {
        $teamId = $user->currentTeam()?->id;
        if ($teamId === null) {
            return false;
        }

        foreach (app(AccessResolver::class)->memberGrants($user, $teamId) ?? [] as $grant) {
            foreach ($grant->levels as $granted) {
                if (in_array($level, $granted->includes(), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function belongsToUserAndCurrentTeam(User $user, PersonalAccessToken $token): bool
    {
        if ($user->id !== $token->tokenable_id || $token->tokenable_type !== User::class) {
            return false;
        }

        $currentTeamId = $user->currentTeam()?->id;

        if ($currentTeamId === null || $token->team_id === null) {
            return false;
        }

        return (string) $currentTeamId === (string) $token->team_id;
    }
}
