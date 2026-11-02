<?php

namespace App\Policies;

use App\Models\GiteaApp;
use App\Models\User;

class GiteaAppPolicy
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
    public function view(User $user, GiteaApp $giteaApp): bool
    {
        if ($giteaApp->is_system_wide) {
            return true;
        }

        return $user->teams->contains('id', $giteaApp->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can make a Gitea App available to every team on the instance.
     */
    public function makeSystemWide(User $user): bool
    {
        return $user->canAccessSystemResources();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, GiteaApp $giteaApp): bool
    {
        if ($giteaApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        // Guard null team_id (e.g. post-delete Livewire re-render of @can checks).
        if ($giteaApp->team_id === null) {
            return false;
        }

        return $user->isAdminOfTeam($giteaApp->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GiteaApp $giteaApp): bool
    {
        if ($giteaApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        // Guard null team_id (e.g. post-delete Livewire re-render of @can checks).
        if ($giteaApp->team_id === null) {
            return false;
        }

        return $user->isAdminOfTeam($giteaApp->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, GiteaApp $giteaApp): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, GiteaApp $giteaApp): bool
    {
        return false;
    }
}
