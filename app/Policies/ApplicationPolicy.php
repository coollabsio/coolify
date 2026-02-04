<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApplicationPolicy
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
    public function view(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('view', $application);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Creation requires at least admin role or project-level manage permission
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Application $application): Response
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return Response::allow();
        }

        if ($user->canPerform('update', $application)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to update this application. You need manage permission for this project.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $application);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('update', $application);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $application);
    }

    /**
     * Determine whether the user can deploy the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('deploy', $application);
    }

    /**
     * Determine whether the user can manage deployments.
     */
    public function manageDeployments(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->canPerform('deploy', $application);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, Application $application): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Environment variables require manage permission
        return $user->canPerform('update', $application);
    }

    /**
     * Determine whether the user can cleanup deployment queue.
     */
    public function cleanupDeploymentQueue(User $user): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        return $user->isAdmin() || $user->isOwner();
    }
}
