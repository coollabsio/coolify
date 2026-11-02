<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\NodeWorkload;
use App\Models\User;
use App\Services\Access\AccessResolver;

class NodeWorkloadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeWorkload $nodeWorkload): bool
    {
        // A workload in an environment follows the access grants there, like other resources.
        return $nodeWorkload->environment_id !== null
            ? $user->canAccess(AccessLevel::Read, $nodeWorkload)
            : app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $nodeWorkload->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $user->isAdminOfTeam($nodeWorkload->team_id);
    }

    public function delete(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $user->isAdminOfTeam($nodeWorkload->team_id);
    }

    /**
     * Determine whether the user can view sensitive values, such as environment variable values.
     */
    public function viewSensitive(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $user->isAdminOfTeam($nodeWorkload->team_id);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $user->isAdminOfTeam($nodeWorkload->team_id);
    }
}
