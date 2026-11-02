<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\SharedEnvironmentVariable;
use App\Models\User;
use App\Services\Access\AccessResolver;

/**
 * Team and server variables need team-wide access (owners and admins to change them).
 * Project and environment variables follow the access level on their project or environment.
 */
class SharedEnvironmentVariablePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return $this->allows($user, AccessLevel::Read, $sharedEnvironmentVariable);
    }

    /**
     * Determine whether the user can read the value.
     */
    public function viewSensitive(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return $this->allows($user, AccessLevel::ReadSensitive, $sharedEnvironmentVariable);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return $this->allows($user, AccessLevel::Write, $sharedEnvironmentVariable);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return $this->allows($user, AccessLevel::Write, $sharedEnvironmentVariable);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        return $this->allows($user, AccessLevel::Write, $sharedEnvironmentVariable);
    }

    private function allows(User $user, AccessLevel $level, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (in_array($sharedEnvironmentVariable->type, ['project', 'environment'], true)) {
            $target = $sharedEnvironmentVariable->type === 'project'
                ? $sharedEnvironmentVariable->project
                : $sharedEnvironmentVariable->environment;

            return $target !== null && $user->canAccess($level, $target);
        }

        return $level === AccessLevel::Read
            ? app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $sharedEnvironmentVariable->team_id)
            : $user->isAdminOfTeam($sharedEnvironmentVariable->team_id);
    }
}
