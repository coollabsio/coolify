<?php

namespace App\Policies;

use App\Models\GithubApp;
use App\Models\User;

class GithubAppPolicy
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
    public function view(User $user, GithubApp $githubApp): bool
    {
        if ($githubApp->is_system_wide) {
            return true;
        }

        return $user->teams->contains('id', $githubApp->team_id);
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
    public function update(User $user, GithubApp $githubApp): bool
    {
        if ($githubApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        return $user->isAdminOfTeam($githubApp->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GithubApp $githubApp): bool
    {
        if ($githubApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        return $user->isAdminOfTeam($githubApp->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, GithubApp $githubApp): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, GithubApp $githubApp): bool
    {
        return false;
    }
}
