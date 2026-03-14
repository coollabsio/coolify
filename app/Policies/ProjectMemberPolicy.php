<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;

class ProjectMemberPolicy
{
    /**
     * Determine if the user can view project members.
     * Team admins/owners can always view.
     * Project managers can view.
     */
    public function viewAny(User $user, Project $project): bool
    {
        // Team owners/admins can always view
        if ($this->isTeamAdminOrOwner($user, $project)) {
            return true;
        }

        // Project managers can view
        $membership = $project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }

    /**
     * Determine if the user can invite/add project members.
     * Only team admins/owners and project managers can invite.
     */
    public function create(User $user, Project $project): bool
    {
        if ($this->isTeamAdminOrOwner($user, $project)) {
            return true;
        }

        $membership = $project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }

    /**
     * Determine if the user can update a project member's role.
     * Only team admins/owners and project managers can update.
     */
    public function update(User $user, Project $project): bool
    {
        if ($this->isTeamAdminOrOwner($user, $project)) {
            return true;
        }

        $membership = $project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }

    /**
     * Determine if the user can remove a project member.
     * Only team admins/owners and project managers can remove.
     */
    public function delete(User $user, Project $project): bool
    {
        if ($this->isTeamAdminOrOwner($user, $project)) {
            return true;
        }

        $membership = $project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }

    /**
     * Check if user is an admin or owner of the project's team.
     */
    private function isTeamAdminOrOwner(User $user, Project $project): bool
    {
        $team = $user->teams->where('id', $project->team_id)->first();
        if (! $team) {
            return false;
        }

        $role = $team->pivot->role ?? null;

        return $role === 'admin' || $role === 'owner';
    }
}
