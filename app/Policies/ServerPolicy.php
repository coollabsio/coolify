<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Server;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Auth\Access\Response;

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
     * Determine whether the user can read the shared variables of the server (team-wide read).
     */
    public function viewSharedVariables(User $user, Server $server): bool
    {
        return app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $server->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can place a resource on the server: create, clone, or migrate one there,
     * or add the server to an application.
     *
     * The Coolify host needs a team admin, which is an instance admin for the localhost server.
     * Other servers need team-wide write, or write on a resource that already runs there.
     */
    public function placeResource(User $user, Server $server): Response
    {
        $resolver = app(AccessResolver::class);
        $teamId = (int) $server->team_id;
        $teamWideWrite = $resolver->allowsTeamWide($user, AccessLevel::Write, $teamId);

        if ($server->isLocalhost()) {
            return $teamWideWrite && $user->isAdminOfTeam($teamId)
                ? Response::allow()
                : Response::deny('Only an admin can place resources on the server that runs Coolify.');
        }

        return $teamWideWrite || $resolver->allowsOnServer($user, AccessLevel::Write, $server)
            ? Response::allow()
            : Response::deny('You need write access on the whole team, or on a resource that already runs on this server, to place resources on it.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Server $server): bool
    {
        return $user->isAdminOfTeam($server->team_id);
    }

    /**
     * Determine whether the user can transfer the server to another Coolify instance.
     *
     * A transfer sends every secret of the server, also locked environment variables, to the target, so only owners may start it.
     */
    public function transfer(User $user, Server $server): bool
    {
        return $user->roleInTeam($server->team_id) === 'owner';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Server $server): bool
    {
        return $user->isAdminOfTeam($server->team_id);
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
        return $user->isAdminOfTeam($server->team_id);
    }

    /**
     * Determine whether the user can manage sentinel (start/stop).
     */
    public function manageSentinel(User $user, Server $server): bool
    {
        return $user->isAdminOfTeam($server->team_id);
    }

    /**
     * Determine whether the user can view Sentinel configuration and logs.
     */
    public function viewSentinel(User $user, Server $server): bool
    {
        return $user->isAdminOfTeam($server->team_id);
    }

    /**
     * Determine whether the user can manage CA certificates.
     */
    public function manageCaCertificate(User $user, Server $server): bool
    {
        return $user->isAdminOfTeam($server->team_id);
    }

    /**
     * Determine whether the user can view security views.
     */
    public function viewSecurity(User $user, Server $server): bool
    {
        return $user->isAdminOfTeam($server->team_id);
    }
}
