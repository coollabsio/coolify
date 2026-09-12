<?php

namespace App\Policies;

use App\Models\NodeWorkload;
use App\Models\User;

class NodeWorkloadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $user->teams->contains('id', $nodeWorkload->team_id);
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
}
