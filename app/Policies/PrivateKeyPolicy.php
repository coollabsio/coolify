<?php

namespace App\Policies;

use App\Models\PrivateKey;
use App\Models\User;

class PrivateKeyPolicy
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
    public function view(User $user, PrivateKey $privateKey): bool
    {
        // Handle null team_id
        if ($privateKey->team_id === null) {
            return false;
        }

        // System resource (team_id=0): Only root team admins/owners can access
        if ($privateKey->team_id === 0) {
            return $user->canAccessSystemResources();
        }

        // Regular resource: Check team membership
        return $user->teams->contains('id', $privateKey->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only admins/owners can create private keys
        // Members should not be able to create SSH keys that could be used for deployments
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PrivateKey $privateKey): bool
    {
        // Handle null team_id
        if ($privateKey->team_id === null) {
            return false;
        }

        // System resource (team_id=0): Only root team admins/owners can update
        if ($privateKey->team_id === 0) {
            return $user->canAccessSystemResources();
        }

        // Regular resource: Must be admin/owner of the team
        return $user->isAdminOfTeam($privateKey->team_id)
            && $user->teams->contains('id', $privateKey->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PrivateKey $privateKey): bool
    {
        // Handle null team_id
        if ($privateKey->team_id === null) {
            return false;
        }

        // System resource (team_id=0): Only root team admins/owners can delete
        if ($privateKey->team_id === 0) {
            return $user->canAccessSystemResources();
        }

        // Regular resource: Must be admin/owner of the team
        return $user->isAdminOfTeam($privateKey->team_id)
            && $user->teams->contains('id', $privateKey->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PrivateKey $privateKey): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PrivateKey $privateKey): bool
    {
        return false;
    }
}
