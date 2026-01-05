<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
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
    public function view(User $user, Team $team): bool
    {
        return $user->teams->contains('id', $team->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // All authenticated users can create teams
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        // Only admins and owners can update team settings
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        // Only admins and owners can delete teams
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can manage team members.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        // Only admins and owners can manage team members
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can view admin panel.
     */
    public function viewAdmin(User $user, Team $team): bool
    {
        // Only admins and owners can view admin panel
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can manage invitations.
     */
    public function manageInvitations(User $user, Team $team): bool
    {
        // Only admins and owners can manage invitations
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }
}
