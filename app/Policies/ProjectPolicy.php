<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;

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
        return app(AccessResolver::class)->canOpen($user, $project);
    }

    /**
     * Determine whether the user can create projects in the current team.
     */
    public function create(User $user): bool
    {
        $teamId = $user->currentTeam()?->id;

        return $teamId !== null && app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, $teamId);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->canAccess(AccessLevel::Write, $project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->canAccess(AccessLevel::Delete, $project);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }
}
