<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        if ($project->team_id === currentTeam()->id) {
            return true;
        }

        return $project->isProjectMember($user->id);
    }

    public function update(User $user, Project $project): bool
    {
        if ($project->team_id !== currentTeam()->id) {
            return false;
        }

        $role = auth()->user()?->role();

        return in_array($role, ['owner', 'admin'], true);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        if ($project->team_id !== currentTeam()->id) {
            return false;
        }

        $role = auth()->user()?->role();

        return in_array($role, ['owner', 'admin'], true);
    }
}
