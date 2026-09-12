<?php

namespace App\Policies;

use App\Models\NodeContainer;
use App\Models\User;

class NodeContainerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeContainer $nodeContainer): bool
    {
        return $user->teams->contains('id', $nodeContainer->node->team_id);
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
