<?php

namespace App\Policies\V5;

use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use Illuminate\Auth\Access\Response;

class ClusterPolicy
{
    /**
     * Determine whether the user can view the cluster within the current team.
     * Read-only, so gated on team membership alone.
     */
    public function view(User $user, Cluster $cluster, Team $team): Response
    {
        return $this->belongsToTeam($cluster, $team);
    }

    /**
     * Determine whether the user can create a cluster in the current team.
     * There is no model to scope yet, so this is a pure role gate.
     */
    public function create(User $user, Team $team): Response
    {
        return $this->allowIfAdmin($user, $team);
    }

    /**
     * Determine whether the user can delete the cluster within the current team.
     */
    public function delete(User $user, Cluster $cluster, Team $team): Response
    {
        $scope = $this->belongsToTeam($cluster, $team);

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
            : Response::deny('You do not have permission to manage clusters in this team.');
    }

    /**
     * Clusters outside the current team must stay invisible, so mismatches
     * deny as not found instead of forbidden.
     */
    private function belongsToTeam(Cluster $cluster, Team $team): Response
    {
        return $cluster->team_id === $team->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
