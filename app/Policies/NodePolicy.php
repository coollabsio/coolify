<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

class NodePolicy
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
    public function view(User $user, Node $node): bool
    {
        return $user->teams->contains('id', $node->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Node $node): bool
    {
        return $user->isAdminOfTeam($node->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Node $node): bool
    {
        return $user->isAdminOfTeam($node->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Node $node): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Node $node): bool
    {
        return false;
    }

    public function manageSentinel(User $user, Node $node): bool
    {
        return $user->isAdminOfTeam($node->team_id);
    }
}
