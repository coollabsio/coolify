<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApplicationPolicy
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
    public function view(User $user, Application $application): bool
    {
        return $user->canAccess(AccessLevel::Read, $application);
    }

    /**
     * Determine whether the user can create models, in the environment when one is given.
     */
    public function create(User $user, ?Environment $environment = null): bool
    {
        return app(ResourceCreatePolicy::class)->createAny($user, $environment);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Application $application): Response
    {
        if ($user->canAccess(AccessLevel::Write, $application)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to update this application.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Application $application): bool
    {
        return $user->canAccess(AccessLevel::Delete, $application);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Application $application): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Application $application): bool
    {
        return false;
    }

    /**
     * Determine whether the user can upload a backup archive for this application.
     */
    public function uploadBackup(User $user, Application $application): Response
    {
        if ($user->canAccess(AccessLevel::Write, $application)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to upload backups for this application.');
    }

    /**
     * Determine whether the user can deploy, restart, or stop the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        return $user->canAccess(AccessLevel::Deploy, $application);
    }

    /**
     * Determine whether the user can read secret values, such as environment variable values.
     */
    public function viewSensitive(User $user, Application $application): bool
    {
        return $user->canAccess(AccessLevel::ReadSensitive, $application);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, Application $application): bool
    {
        return $user->canAccess(AccessLevel::Write, $application);
    }
}
