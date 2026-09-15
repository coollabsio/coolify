<?php

namespace App\Policies;

use App\Models\AgeKey;
use App\Models\User;

class AgeKeyPolicy
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
    public function view(User $user, AgeKey $ageKey): bool
    {
        if ($ageKey->team_id === null) {
            return false;
        }

        if ($ageKey->team_id === 0) {
            return $user->canAccessSystemResources();
        }

        return $user->teams->contains('id', $ageKey->team_id);
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
    public function update(User $user, AgeKey $ageKey): bool
    {
        if ($ageKey->team_id === null) {
            return false;
        }

        if ($ageKey->team_id === 0) {
            return $user->canAccessSystemResources();
        }

        return $user->isAdminOfTeam($ageKey->team_id)
            && $user->teams->contains('id', $ageKey->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AgeKey $ageKey): bool
    {
        if ($ageKey->team_id === null) {
            return false;
        }

        if ($ageKey->team_id === 0) {
            return $user->canAccessSystemResources();
        }

        return $user->isAdminOfTeam($ageKey->team_id)
            && $user->teams->contains('id', $ageKey->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AgeKey $ageKey): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AgeKey $ageKey): bool
    {
        return false;
    }
}
