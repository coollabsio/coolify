<?php

namespace App\Policies\V5;

use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server;
use Illuminate\Auth\Access\Response;

class ServerPolicy
{
    /**
     * Determine whether the user can add a server to the cluster within the
     * current team. Denies as forbidden (not "not found") to preserve the
     * historical 403 on cluster/team mismatch, and requires an admin/owner
     * role to mutate cluster infrastructure.
     */
    public function create(User $user, Team $team, Cluster $cluster): Response
    {
        if ($cluster->team_id !== $team->id) {
            return Response::deny();
        }

        return $this->allowIfAdmin($user, $team);
    }

    /**
     * Determine whether the user can update the server within the current team.
     */
    public function update(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        return $this->allowIfAdminAndScoped($user, $server, $team, $cluster);
    }

    /**
     * Determine whether the user can delete the server within the current team.
     */
    public function delete(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        return $this->allowIfAdminAndScoped($user, $server, $team, $cluster);
    }

    /**
     * Determine whether the user can run a connectivity check against the server.
     */
    public function check(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        return $this->allowIfAdminAndScoped($user, $server, $team, $cluster);
    }

    /**
     * Determine whether the user can bootstrap the server.
     */
    public function bootstrap(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        return $this->allowIfAdminAndScoped($user, $server, $team, $cluster);
    }

    /**
     * Determine whether the user can restart coold over SSH.
     */
    public function restartCoold(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        return $this->allowIfAdminAndScoped($user, $server, $team, $cluster);
    }

    /**
     * Determine whether the user can view server diagnostics (coold logs,
     * corrosion tables, firewall rules). Read-only, so gated on team
     * membership alone.
     */
    public function viewDiagnostics(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        return $this->belongsToClusterInTeam($server, $team, $cluster);
    }

    /**
     * Determine whether the user can move the server's Caddy ingress card on
     * the canvas. Non-ingress servers must stay invisible on the canvas, and
     * moving a card mutates persisted layout so it requires an admin/owner.
     */
    public function updateCanvasPosition(User $user, Server $server, Team $team): Response
    {
        if (! ($server->team_id === $team->id && $server->isIngress())) {
            return Response::denyAsNotFound();
        }

        return $this->allowIfAdmin($user, $team);
    }

    /**
     * Run the team/cluster scoping check first (mismatch stays hidden as a 404)
     * and only then the role check (403 for members on their own team's server).
     */
    private function allowIfAdminAndScoped(User $user, Server $server, Team $team, Cluster $cluster): Response
    {
        $scope = $this->belongsToClusterInTeam($server, $team, $cluster);

        if ($scope->denied()) {
            return $scope;
        }

        return $this->allowIfAdmin($user, $team);
    }

    /**
     * Members may read but not mutate; only admins/owners of the team pass.
     */
    private function allowIfAdmin(User $user, Team $team): Response
    {
        return $user->isAdminOfTeam($team->id)
            ? Response::allow()
            : Response::deny('You do not have permission to manage servers in this team.');
    }

    /**
     * Servers outside the current team (or outside the addressed cluster)
     * must stay invisible, so mismatches deny as not found.
     */
    private function belongsToClusterInTeam(Server $server, Team $team, Cluster $cluster): Response
    {
        return $cluster->team_id === $team->id
            && $server->team_id === $team->id
            && $server->cluster_id === $cluster->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
