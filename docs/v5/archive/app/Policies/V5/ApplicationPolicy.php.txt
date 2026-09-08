<?php

namespace App\Policies\V5;

use App\Models\Team;
use App\Models\User;
use App\Models\V5\Application;
use Illuminate\Auth\Access\Response;

class ApplicationPolicy
{
    public function create(User $user, Team $team): Response
    {
        return $user->isAdminOfTeam($team->id)
            ? Response::allow()
            : Response::deny('You do not have permission to manage applications in this team.');
    }

    /**
     * Determine whether the user can view the application within the current team.
     *
     * Read-only diagnostics (deploy status, container logs) are available to any
     * member of the owning team; apps from other teams stay hidden as a 404.
     */
    public function view(User $user, Application $application, Team $team): Response
    {
        return $this->belongsToTeam($application, $team);
    }

    /**
     * Determine whether the user can update the application within the current team.
     */
    public function update(User $user, Application $application, Team $team): Response
    {
        return $this->allowIfAdminAndScoped($user, $application, $team);
    }

    /**
     * Determine whether the user can update the application's ingress configuration.
     */
    public function updateIngress(User $user, Application $application, Team $team): Response
    {
        return $this->allowIfAdminAndScoped($user, $application, $team);
    }

    /**
     * Determine whether the user can delete the application within the current team.
     */
    public function delete(User $user, Application $application, Team $team): Response
    {
        return $this->allowIfAdminAndScoped($user, $application, $team);
    }

    /**
     * Run the team scoping check first (mismatch stays hidden as a 404) and
     * only then the role check (403 for members on their own team's app).
     */
    private function allowIfAdminAndScoped(User $user, Application $application, Team $team): Response
    {
        $scope = $this->belongsToTeam($application, $team);

        if ($scope->denied()) {
            return $scope;
        }

        return $user->isAdminOfTeam($team->id)
            ? Response::allow()
            : Response::deny('You do not have permission to manage applications in this team.');
    }

    /**
     * Applications outside the current team must stay invisible, so
     * mismatches deny as not found instead of forbidden.
     */
    private function belongsToTeam(Application $application, Team $team): Response
    {
        return $application->team_id === $team->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
