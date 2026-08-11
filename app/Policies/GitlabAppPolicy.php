<?php

namespace App\Policies;

use App\Models\GitlabApp;
use App\Models\User;

class GitlabAppPolicy
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
    public function view(User $user, GitlabApp $gitlabApp): bool
    {
        if ($gitlabApp->is_system_wide) {
            return true;
        }

        return $user->teams->contains('id', $gitlabApp->team_id);
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
    public function update(User $user, GitlabApp $gitlabApp): bool
    {
        if ($gitlabApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        // Guard null team_id (e.g. post-delete Livewire re-render of @can checks).
        if ($gitlabApp->team_id === null) {
            return false;
        }

        return $user->isAdminOfTeam($gitlabApp->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GitlabApp $gitlabApp): bool
    {
        if ($gitlabApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        // Guard null team_id (e.g. post-delete Livewire re-render of @can checks).
        if ($gitlabApp->team_id === null) {
            return false;
        }

        return $user->isAdminOfTeam($gitlabApp->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, GitlabApp $gitlabApp): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, GitlabApp $gitlabApp): bool
    {
        return false;
    }
}
