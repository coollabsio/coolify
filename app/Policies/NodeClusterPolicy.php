<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\NodeCluster;
use App\Models\User;
use App\Services\Access\AccessResolver;

class NodeClusterPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeCluster $cluster): bool
    {
        return app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $cluster->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, NodeCluster $cluster): bool
    {
        return $user->isAdminOfTeam($cluster->team_id);
    }

    public function delete(User $user, NodeCluster $cluster): bool
    {
        return $user->isAdminOfTeam($cluster->team_id);
    }
}
