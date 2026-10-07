<?php

namespace App\Policies;

use App\Models\CloudProviderToken;
use App\Models\User;

class CloudProviderTokenPolicy
{
    /**
     * Listing and creating happen on the session team's security pages.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * A loaded resource is checked against its own team: the session team can differ.
     */
    public function view(User $user, CloudProviderToken $cloudProviderToken): bool
    {
        return $user->isAdminOfTeam((int) $cloudProviderToken->team_id);
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
    public function update(User $user, CloudProviderToken $cloudProviderToken): bool
    {
        return $user->isAdminOfTeam((int) $cloudProviderToken->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CloudProviderToken $cloudProviderToken): bool
    {
        return $user->isAdminOfTeam((int) $cloudProviderToken->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CloudProviderToken $cloudProviderToken): bool
    {
        return $user->isAdminOfTeam((int) $cloudProviderToken->team_id);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CloudProviderToken $cloudProviderToken): bool
    {
        return $user->isAdminOfTeam((int) $cloudProviderToken->team_id);
    }
}
