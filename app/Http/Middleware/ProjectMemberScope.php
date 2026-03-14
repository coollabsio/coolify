<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\ProjectMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProjectMemberScope
{
    /**
     * Restrict project-specific members from accessing resources outside their assigned projects.
     *
     * Project-specific members are users who:
     * - Have entries in the project_members table
     * - Are NOT full team members (owner/admin) of the team
     *
     * These users CANNOT access:
     * - Servers
     * - SSH/Private keys
     * - Other projects they are not assigned to
     * - Team settings
     * - Terminal access
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if (! $user) {
            return $next($request);
        }

        // Team owners and admins bypass all restrictions
        if ($user->isAdmin() || $user->isOwner()) {
            return $next($request);
        }

        // Check if user is a project-specific member (has project_members entries)
        $isProjectSpecificMember = $this->isProjectSpecificMember($user);
        if (! $isProjectSpecificMember) {
            // Regular team members pass through (they have full team access)
            return $next($request);
        }

        // Project-specific members: restrict access
        $routeName = $request->route()?->getName();

        // Block access to servers
        if ($this->isServerRoute($routeName)) {
            abort(403, 'Project members do not have access to servers.');
        }

        // Block access to security/keys
        if ($this->isSecurityRoute($routeName)) {
            abort(403, 'Project members do not have access to security settings.');
        }

        // Block access to team settings
        if ($this->isTeamSettingsRoute($routeName)) {
            abort(403, 'Project members do not have access to team settings.');
        }

        // Block terminal access
        if ($this->isTerminalRoute($routeName)) {
            abort(403, 'Project members do not have terminal access.');
        }

        // Block access to instance settings
        if ($this->isSettingsRoute($routeName)) {
            abort(403, 'Project members do not have access to settings.');
        }

        // For project routes, verify the user has access to the specific project
        if ($this->isProjectRoute($routeName)) {
            $projectUuid = $request->route('project_uuid');
            if ($projectUuid) {
                $project = Project::where('uuid', $projectUuid)->first();
                if ($project && ! $this->userCanAccessProject($user, $project)) {
                    abort(403, 'You do not have access to this project.');
                }
            }
        }

        return $next($request);
    }

    private function isProjectSpecificMember($user): bool
    {
        $team = currentTeam();
        if (! $team) {
            return false;
        }

        // If user is admin or owner of the team, they are not a project-specific member
        $teamRole = $user->teams->where('id', $team->id)->first()?->pivot?->role;
        if (in_array($teamRole, ['admin', 'owner'])) {
            return false;
        }

        // Check if user has any project memberships in this team's projects
        $teamProjectIds = $team->projects()->pluck('id');

        return ProjectMember::where('user_id', $user->id)
            ->whereIn('project_id', $teamProjectIds)
            ->exists();
    }

    private function userCanAccessProject($user, Project $project): bool
    {
        return ProjectMember::where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->exists();
    }

    private function isServerRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        return str_starts_with($routeName, 'server.');
    }

    private function isSecurityRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        return str_starts_with($routeName, 'security.');
    }

    private function isTeamSettingsRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        return str_starts_with($routeName, 'team.') && $routeName !== 'team.invitation.accept';
    }

    private function isTerminalRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        return $routeName === 'terminal';
    }

    private function isSettingsRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        return str_starts_with($routeName, 'settings.');
    }

    private function isProjectRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        return str_starts_with($routeName, 'project.');
    }
}
