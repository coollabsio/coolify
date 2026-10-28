<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;

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
        return app(AccessResolver::class)->canOpen($user, $environment);
    }

    /**
     * Determine whether the user can create environments, in the project when one is given.
     */
    public function create(User $user, ?Project $project = null): bool
    {
        return $project === null
            ? app(ProjectPolicy::class)->create($user)
            : $user->canAccess(AccessLevel::Write, $project);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Environment $environment): bool
    {
        return $user->canAccess(AccessLevel::Write, $environment);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Environment $environment): bool
    {
        return $user->canAccess(AccessLevel::Delete, $environment);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Environment $environment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Environment $environment): bool
    {
        return false;
    }
}
