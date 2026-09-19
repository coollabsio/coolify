<?php

namespace App\Policies;

use App\Models\InfisicalBinding;
use App\Models\User;

class InfisicalBindingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InfisicalBinding $binding): bool
    {
        return $this->manages($user, $binding);
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
    public function update(User $user, InfisicalBinding $binding): bool
    {
        return $this->manages($user, $binding);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InfisicalBinding $binding): bool
    {
        return $this->manages($user, $binding);
    }

    /**
     * A binding has no team_id of its own; its team is resolved through the
     * owning connection. A binding whose connection is missing must never be
     * treated as permitted — deny rather than risk a null team id matching
     * an unrelated team.
     */
    private function manages(User $user, InfisicalBinding $binding): bool
    {
        $connection = $binding->connection;

        if ($connection === null) {
            return false;
        }

        return $user->isAdminOfTeam($connection->team_id);
    }
}
