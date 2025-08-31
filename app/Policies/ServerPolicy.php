<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
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
    public function view(User $user, Server $server): bool
    {
        return $user->teams->contains('id', $server->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // return $user->isAdmin();
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Server $server): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $server->team_id);
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Server $server): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $server->team_id);
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Server $server): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Server $server): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage proxy (start/stop/restart).
     */
    public function manageProxy(User $user, Server $server): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $server->team_id);
        return true;
    }

    /**
     * Determine whether the user can manage sentinel (start/stop).
     */
    public function manageSentinel(User $user, Server $server): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $server->team_id);
        return true;
    }

    /**
     * Determine whether the user can manage CA certificates.
     */
    public function manageCaCertificate(User $user, Server $server): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $server->team_id);
        return true;
    }

    /**
     * Determine whether the user can view security views.
     */
    public function viewSecurity(User $user, Server $server): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $server->team_id);
        return true;
    }
}
