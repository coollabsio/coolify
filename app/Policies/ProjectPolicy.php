<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Check if granular permissions feature is enabled.
     */
    protected function isGranularPermissionsEnabled(): bool
    {
        return config('constants.features.granular_permissions', false);
    }

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
    public function view(User $user, Project $project): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('view', $project);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Only admins and owners can create projects
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('update', $project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $project);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('update', $project);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $project);
    }

    /**
     * Determine whether the user can manage project access.
     */
    public function manageAccess(User $user, Project $project): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return $user->isAdmin() || $user->isOwner();
        }

        // Only admins and owners can manage project access
        return $user->isAdmin() || $user->isOwner();
    }
}
