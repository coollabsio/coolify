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
        $teamId = $this->getTeamId($application);

        return $teamId !== null && $user->teams->contains('id', $teamId);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->canManageResources();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Application $application): Response
    {
        $teamId = $this->getTeamId($application);

        if ($teamId === null) {
            return Response::deny('Application team not found.');
        }

        if ($user->canManageResourcesOfTeam($teamId)) {
            return Response::allow();
        }

        return Response::deny('You need at least operator permissions to update this application.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $teamId !== null && $user->canManageResourcesOfTeam($teamId);
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
        $teamId = $this->getTeamId($application);

        if ($teamId === null) {
            return Response::deny('Application team not found.');
        }

        if ($user->canManageResourcesOfTeam($teamId)) {
            return Response::allow();
        }

        return Response::deny('You need at least operator permissions to upload backups for this application.');
    }

    /**
     * Determine whether the user can deploy the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $teamId !== null && $user->canManageResourcesOfTeam($teamId);
    }

    /**
     * Determine whether the user can manage deployments.
     */
    public function manageDeployments(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $teamId !== null && $user->canManageResourcesOfTeam($teamId);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $teamId !== null && $user->canManageResourcesOfTeam($teamId);
    }

    /**
     * Determine whether the user can cleanup deployment queue.
     */
    public function cleanupDeploymentQueue(User $user): bool
    {
        return $user->canManageResources();
    }

    private function getTeamId(Application $application): ?int
    {
        return $application->team()?->id;
    }
}
