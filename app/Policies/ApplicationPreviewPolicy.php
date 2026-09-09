<?php

namespace App\Policies;

use App\Models\ApplicationPreview;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApplicationPreviewPolicy
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
    public function view(User $user, ApplicationPreview $applicationPreview): bool
    {
        $teamId = $this->getTeamId($applicationPreview);

        return $teamId !== null && $user->teams->contains('id', $teamId);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ApplicationPreview $applicationPreview): Response
    {
        $teamId = $this->getTeamId($applicationPreview);

        if ($teamId === null) {
            return Response::deny('Application preview team not found.');
        }

        if ($user->isAdminOfTeam($teamId)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to update this preview.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ApplicationPreview $applicationPreview): bool
    {
        $teamId = $this->getTeamId($applicationPreview);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ApplicationPreview $applicationPreview): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ApplicationPreview $applicationPreview): bool
    {
        return false;
    }

    /**
     * Determine whether the user can deploy the preview.
     */
    public function deploy(User $user, ApplicationPreview $applicationPreview): bool
    {
        $teamId = $this->getTeamId($applicationPreview);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can manage preview deployments.
     */
    public function manageDeployments(User $user, ApplicationPreview $applicationPreview): bool
    {
        $teamId = $this->getTeamId($applicationPreview);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    private function getTeamId(ApplicationPreview $applicationPreview): ?int
    {
        return $applicationPreview->application?->team()?->id;
    }
}
