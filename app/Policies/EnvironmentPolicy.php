<?php

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
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
    public function view(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->hasEnvironmentPermission($environment, 'view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Only admins and owners can create environments
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Uses project permission cascade
        return $user->hasEnvironmentPermission($environment, 'manage') ||
               $user->hasProjectPermission($environment->project, 'manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->hasProjectPermission($environment->project, 'delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->hasProjectPermission($environment->project, 'manage');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->hasProjectPermission($environment->project, 'delete');
    }

    /**
     * Determine whether the user can deploy to the environment.
     */
    public function deploy(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->hasEnvironmentPermission($environment, 'deploy');
    }

    /**
     * Determine whether the user can access secrets in the environment.
     */
    public function accessSecrets(User $user, Environment $environment): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canAccessEnvironmentSecrets($environment);
    }
}
