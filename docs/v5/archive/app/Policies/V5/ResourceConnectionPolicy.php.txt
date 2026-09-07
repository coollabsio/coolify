<?php

namespace App\Policies\V5;

use App\Models\Team;
use App\Models\User;
use App\Models\V5\ResourceConnection;
use Illuminate\Auth\Access\Response;

class ResourceConnectionPolicy
{
    public function create(User $user, Team $team): Response
    {
        return $user->isAdminOfTeam($team->id)
            ? Response::allow()
            : Response::deny('You do not have permission to manage resource connections in this team.');
    }

    /**
     * Determine whether the user can update the connection within the current team.
     */
    public function update(User $user, ResourceConnection $connection, Team $team): Response
    {
        return $this->allowIfAdminAndScoped($user, $connection, $team);
    }

    /**
     * Determine whether the user can delete the connection within the current team.
     */
    public function delete(User $user, ResourceConnection $connection, Team $team): Response
    {
        return $this->allowIfAdminAndScoped($user, $connection, $team);
    }

    /**
     * Run the team scoping check first (mismatch stays hidden as a 404) and
     * only then the role check (403 for members on their own team's connection).
     */
    private function allowIfAdminAndScoped(User $user, ResourceConnection $connection, Team $team): Response
    {
        $scope = $this->belongsToTeam($connection, $team);

        if ($scope->denied()) {
            return $scope;
        }

        return $user->isAdminOfTeam($team->id)
            ? Response::allow()
            : Response::deny('You do not have permission to manage resource connections in this team.');
    }

    /**
     * Connections outside the current team must stay invisible, so
     * mismatches deny as not found instead of forbidden.
     */
    private function belongsToTeam(ResourceConnection $connection, Team $team): Response
    {
        return $connection->team_id === $team->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
