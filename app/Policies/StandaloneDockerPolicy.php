<?php

namespace App\Policies;

use App\Models\StandaloneDocker;
use App\Models\User;

class StandaloneDockerPolicy
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
    public function view(User $user, StandaloneDocker $standaloneDocker): bool
    {
        return $user->teams->contains('id', $standaloneDocker->server->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // return $user->isAdmin();
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StandaloneDocker $standaloneDocker): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $standaloneDocker->server->team_id);
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StandaloneDocker $standaloneDocker): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $standaloneDocker->server->team_id);
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StandaloneDocker $standaloneDocker): bool
    {
        // return false;
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StandaloneDocker $standaloneDocker): bool
    {
        // return false;
        return true;
    }
}
