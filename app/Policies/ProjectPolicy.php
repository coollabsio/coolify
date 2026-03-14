<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
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
    public function view(User $user, Project $project): bool
    {
        // Team members can always view
        if ($user->teams->contains('id', $project->team_id)) {
            return true;
        }

        // Project-specific members can view
        return $project->isProjectMember($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        // Team admins/owners can update
        if ($user->isAdmin() || $user->isOwner()) {
            return true;
        }

        // Project managers can update
        $membership = $project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        // Only team admins/owners can delete projects
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can deploy resources in the project.
     */
    public function deploy(User $user, Project $project): bool
    {
        // Team admins/owners can always deploy
        if ($user->isAdmin() || $user->isOwner()) {
            return true;
        }

        // Regular team members can deploy
        if ($user->teams->contains('id', $project->team_id) && ! $project->isProjectMember($user)) {
            return true;
        }

        // Project deployers and managers can deploy
        $membership = $project->getProjectMember($user);

        return $membership?->canDeploy() ?? false;
    }

    /**
     * Determine whether the user can manage project members.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        // Team admins/owners can always manage
        if ($user->isAdmin() || $user->isOwner()) {
            return true;
        }

        // Project managers can manage members
        $membership = $project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }
}
