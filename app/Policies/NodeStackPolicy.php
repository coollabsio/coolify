<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\NodeStack;
use App\Models\User;
use App\Services\Access\AccessResolver;

/** The rules of NodeWorkloadPolicy: the services of a stack are cluster applications. */
class NodeStackPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeStack $nodeStack): bool
    {
        return $nodeStack->environment_id !== null
            ? $user->canAccess(AccessLevel::Read, $nodeStack)
            : app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $nodeStack->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Deploy and every other change. */
    public function update(User $user, NodeStack $nodeStack): bool
    {
        return $user->isAdminOfTeam($nodeStack->team_id);
    }

    public function delete(User $user, NodeStack $nodeStack): bool
    {
        return $user->isAdminOfTeam($nodeStack->team_id);
    }
}
