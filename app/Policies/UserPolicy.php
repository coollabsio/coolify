<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the actor can list users in the current team.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdminFromSession();
    }

    /**
     * Determine whether the actor can view a specific user.
     */
    public function view(User $user, User $target): bool
    {
        if (! $user->isAdminFromSession()) {
            return false;
        }

        // The actor can only view users that share at least one of their teams.
        $actorTeamIds = $user->teams->pluck('id');

        return $target->teams()->whereIn('teams.id', $actorTeamIds)->exists();
    }

    /**
     * Determine whether the actor can create a new user.
     */
    public function create(User $user): bool
    {
        return $user->isAdminFromSession();
    }

    /**
     * Determine whether the actor can update a user.
     */
    public function update(User $user, User $target): bool
    {
        if (! $user->isAdminFromSession()) {
            return false;
        }

        // Owners and instance admins can edit anyone they share a team with.
        // Regular admins can edit anyone except owners and instance admins.
        if ($target->isOwner() && ! $user->isOwner() && ! $user->isInstanceAdmin()) {
            return false;
        }

        return $this->view($user, $target);
    }

    /**
     * Determine whether the actor can delete a user.
     */
    public function delete(User $user, User $target): bool
    {
        if (! $user->isAdminFromSession()) {
            return false;
        }

        // No one can delete themselves through this endpoint.
        if ($user->id === $target->id) {
            return false;
        }

        // Owners can never be deleted from the users module — they must
        // first be demoted via the team management screens.
        if ($target->isOwner()) {
            return false;
        }

        // Instance admins are protected from deletion by non-instance-admins.
        if ($target->isInstanceAdmin() && ! $user->isInstanceAdmin()) {
            return false;
        }

        return $this->view($user, $target);
    }
}
