<?php

namespace App\Policies;

use App\Models\CloudInitScript;
use App\Models\User;

class CloudInitScriptPolicy
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
    public function view(User $user, CloudInitScript $cloudInitScript): bool
    {
        return $user->isAdminOfTeam((int) $cloudInitScript->team_id);
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
    public function update(User $user, CloudInitScript $cloudInitScript): bool
    {
        return $user->isAdminOfTeam((int) $cloudInitScript->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CloudInitScript $cloudInitScript): bool
    {
        return $user->isAdminOfTeam((int) $cloudInitScript->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CloudInitScript $cloudInitScript): bool
    {
        return $user->isAdminOfTeam((int) $cloudInitScript->team_id);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CloudInitScript $cloudInitScript): bool
    {
        return $user->isAdminOfTeam((int) $cloudInitScript->team_id);
    }
}
