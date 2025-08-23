<?php

namespace App\Policies;

use App\Models\Application;
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
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Application $application): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        return Response::deny('As a member, you cannot update this application.<br/><br/>You need at least admin or owner permissions.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Application $application): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Application $application): bool
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Application $application): bool
    {
        return $user->isAdmin() && $user->teams()->get()->firstWhere('id', $application->team()->first()->id) !== null;
    }

    /**
     * Determine whether the user can deploy the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        return $user->teams()->get()->firstWhere('id', $application->team()->first()->id) !== null;
    }

    /**
     * Determine whether the user can manage deployments.
     */
    public function manageDeployments(User $user, Application $application): bool
    {
        return $user->isAdmin() && $user->teams()->get()->firstWhere('id', $application->team()->first()->id) !== null;
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, Application $application): bool
    {
        return $user->isAdmin() && $user->teams()->get()->firstWhere('id', $application->team()->first()->id) !== null;
    }
}
