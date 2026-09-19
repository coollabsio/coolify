<?php

namespace App\Policies;

use App\Models\DockerRegistry;
use App\Models\User;

class DockerRegistryPolicy
{
    public function viewAny(User $user): bool
    {
        // return $user->teams->contains('id', $registry->team_id);
        return true;
    }

    public function view(User $user, DockerRegistry $registry): bool
    {
        // return $user->teams->contains('id', $registry->team_id);
        return true;
    }

    public function create(User $user): bool
    {
        // if ($user->isAdmin()) {
        //     return true;
        // }
        return true;
    }

    public function update(User $user, DockerRegistry $registry): bool
    {
        // return $user->teams->contains('id', $registry->team_id);
        return true;
    }

    public function delete(User $user, DockerRegistry $registry): bool
    {
        // return $user->teams->contains('id', $registry->team_id);
        return true;
    }
}
