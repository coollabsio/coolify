<?php

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
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
    public function view(User $user, Environment $environment): bool
    {
        // return $user->teams->contains('id', $environment->project->team_id);
        return true;
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
    public function update(User $user, Environment $environment): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $environment->project->team_id);
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Environment $environment): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $environment->project->team_id);
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Environment $environment): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $environment->project->team_id);
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Environment $environment): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $environment->project->team_id);
        return true;
    }
}
