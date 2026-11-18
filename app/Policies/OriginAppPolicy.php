<?php

namespace App\Policies;

use App\Models\OriginApp;
use App\Models\User;

class OriginAppPolicy
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
    public function view(User $user, OriginApp $originApp): bool
    {
        if ($originApp->is_system_wide) {
            return true;
        }

        return $user->teams->contains('id', $originApp->team_id);
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
    public function update(User $user, OriginApp $originApp): bool
    {
        if ($originApp->is_system_wide) {
            return $user->canAccessSystemResources();
        }

        // Guard null team_id (e.g. post-delete Livewire re-render of @can checks).
        if ($originApp->team_id === null) {
            return false;
        }

        return $user->isAdminOfTeam($originApp->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, OriginApp $originApp): bool
    {
        return $this->update($user, $originApp);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, OriginApp $originApp): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, OriginApp $originApp): bool
    {
        return false;
    }
}
