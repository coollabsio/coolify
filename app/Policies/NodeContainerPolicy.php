<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\NodeContainer;
use App\Models\User;
use App\Services\Access\AccessResolver;

class NodeContainerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeContainer $nodeContainer): bool
    {
        $teamId = $nodeContainer->node?->team_id;

        return $teamId !== null && app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $teamId);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NodeContainer $nodeContainer): bool
    {
        return false;
    }

    public function delete(User $user, NodeContainer $nodeContainer): bool
    {
        return false;
    }
}
