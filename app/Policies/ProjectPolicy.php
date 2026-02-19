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
        // Team owner/admin has full access
        if ($user->teams->contains('id', $project->team_id)) {
            $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;
            if ($role === 'owner' || $role === 'admin') {
                return true;
            }
        }

        // Check if user is a project member
        return $user->isProjectMember($project);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only team admins/owners can create projects
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        // Team owner/admin has full access
        if ($user->teams->contains('id', $project->team_id)) {
            $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;
            if ($role === 'owner' || $role === 'admin') {
                return true;
            }
        }

        // Project admin can update
        return $user->projectRole($project) === 'admin';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        // Only team owner/admin can delete projects
        if (! $user->teams->contains('id', $project->team_id)) {
            return false;
        }

        $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;

        return $role === 'owner' || $role === 'admin';
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        // Only team owner/admin can restore projects
        if (! $user->teams->contains('id', $project->team_id)) {
            return false;
        }

        $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;

        return $role === 'owner' || $role === 'admin';
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        // Only team owner/admin can force delete projects
        if (! $user->teams->contains('id', $project->team_id)) {
            return false;
        }

        $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;

        return $role === 'owner' || $role === 'admin';
    }

    /**
     * Determine whether the user can manage project members.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        // Team owner/admin has full access
        if ($user->teams->contains('id', $project->team_id)) {
            $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;
            if ($role === 'owner' || $role === 'admin') {
                return true;
            }
        }

        // Project admin can manage members
        return $user->projectRole($project) === 'admin';
    }

    /**
     * Determine whether the user can create resources in the project.
     */
    public function createResources(User $user, Project $project): bool
    {
        // Team owner/admin has full access
        if ($user->teams->contains('id', $project->team_id)) {
            $role = $user->teams->where('id', $project->team_id)->first()?->pivot?->role;
            if ($role === 'owner' || $role === 'admin') {
                return true;
            }
        }

        // Project members (admin or member) can create resources
        return $user->isProjectMember($project);
    }
}
