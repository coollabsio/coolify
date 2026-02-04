<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Check if granular permissions feature is enabled.
     */
    protected function isGranularPermissionsEnabled(): bool
    {
        return config('constants.features.granular_permissions', false);
    }

    /**
     * Check if user is in the server's team.
     */
    protected function userInTeam(User $user, Server $server): bool
    {
        return $user->teams->contains('id', $server->team_id);
    }

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
        return $this->userInTeam($user, $server);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Only admins and owners can create servers
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Server $server): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Must be in team and have admin/owner role
        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Server $server): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        // Must be in team and have admin/owner role
        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
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
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can manage sentinel (start/stop).
     */
    public function manageSentinel(User $user, Server $server): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can manage CA certificates.
     */
    public function manageCaCertificate(User $user, Server $server): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can view security views.
     */
    public function viewSecurity(User $user, Server $server): bool
    {
        if (! $this->isGranularPermissionsEnabled()) {
            return true;
        }

        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        // Viewers can view security, but members/admins/owners can also view
        $role = $user->roleInTeam($server->team_id);

        return $role !== null;
    }

    /**
     * Determine whether the user can access the server terminal.
     */
    public function terminal(User $user, Server $server): bool
    {
        if (! $this->userInTeam($user, $server)) {
            return false;
        }

        // Terminal access is always restricted to admin/owner
        return $user->isAdmin() || $user->isOwner();
    }
}
