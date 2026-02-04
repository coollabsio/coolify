<?php

namespace App\Traits;

use App\Models\Environment;
use App\Models\EnvironmentUser;
use App\Models\Project;
use App\Models\ProjectUser;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Trait for handling project-level access control.
 *
 * Provides methods for checking and managing user access to projects
 * based on the granular permission system.
 */
trait HasProjectAccess
{
    /**
     * Get all projects the user has explicit access to.
     */
    public function accessibleProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user')
            ->using(ProjectUser::class)
            ->withPivot('permissions')
            ->withTimestamps();
    }

    /**
     * Get the project access record for a specific project.
     */
    public function getProjectAccess(Project $project): ?ProjectUser
    {
        return ProjectUser::where('user_id', $this->id)
            ->where('project_id', $project->id)
            ->first();
    }

    /**
     * Check if user has explicit access to a project.
     */
    public function hasProjectAccess(Project $project): bool
    {
        return $this->accessibleProjects()
            ->where('project_id', $project->id)
            ->exists();
    }

    /**
     * Check if user has a specific permission on a project.
     */
    public function hasProjectPermission(Project $project, string $permission): bool
    {
        // Owners and admins bypass project-level checks
        if ($this->isOwner() || $this->isAdmin()) {
            return true;
        }

        $projectAccess = $this->getProjectAccess($project);
        if (! $projectAccess) {
            return false;
        }

        return $projectAccess->hasPermission($permission);
    }

    /**
     * Check if user can view a project.
     */
    public function canViewProject(Project $project): bool
    {
        return $this->hasProjectPermission($project, 'view');
    }

    /**
     * Check if user can deploy to a project.
     */
    public function canDeployToProject(Project $project): bool
    {
        return $this->hasProjectPermission($project, 'deploy');
    }

    /**
     * Check if user can manage a project.
     */
    public function canManageProject(Project $project): bool
    {
        return $this->hasProjectPermission($project, 'manage');
    }

    /**
     * Check if user can delete resources in a project.
     */
    public function canDeleteInProject(Project $project): bool
    {
        return $this->hasProjectPermission($project, 'delete');
    }

    /**
     * Grant user access to a project with specified permissions.
     */
    public function grantProjectAccess(Project $project, array $permissions = []): ProjectUser
    {
        $existingAccess = $this->getProjectAccess($project);

        if ($existingAccess) {
            $existingAccess->setPermissions($permissions)->save();

            return $existingAccess;
        }

        return ProjectUser::create([
            'user_id' => $this->id,
            'project_id' => $project->id,
            'permissions' => array_merge(ProjectUser::DEFAULT_PERMISSIONS, $permissions),
        ]);
    }

    /**
     * Revoke user access to a project.
     */
    public function revokeProjectAccess(Project $project): bool
    {
        return ProjectUser::where('user_id', $this->id)
            ->where('project_id', $project->id)
            ->delete() > 0;
    }

    /**
     * Update user's permissions on a project.
     */
    public function updateProjectPermissions(Project $project, array $permissions): ?ProjectUser
    {
        $projectAccess = $this->getProjectAccess($project);

        if (! $projectAccess) {
            return null;
        }

        $projectAccess->setPermissions($permissions)->save();

        return $projectAccess;
    }

    /**
     * Get all environments the user has explicit access to.
     */
    public function accessibleEnvironments(): BelongsToMany
    {
        return $this->belongsToMany(Environment::class, 'environment_user')
            ->using(EnvironmentUser::class)
            ->withPivot('permissions')
            ->withTimestamps();
    }

    /**
     * Get the environment access record for a specific environment.
     */
    public function getEnvironmentAccess(Environment $environment): ?EnvironmentUser
    {
        return EnvironmentUser::where('user_id', $this->id)
            ->where('environment_id', $environment->id)
            ->first();
    }

    /**
     * Check if user has permission on an environment.
     * First checks explicit environment permissions, then cascades from project.
     */
    public function hasEnvironmentPermission(Environment $environment, string $permission): bool
    {
        // Owners and admins bypass all checks
        if ($this->isOwner() || $this->isAdmin()) {
            return true;
        }

        // Check explicit environment permission first
        $envAccess = $this->getEnvironmentAccess($environment);
        if ($envAccess) {
            return $envAccess->hasPermission($permission);
        }

        // Cascade: check project permission (environments inherit from project)
        return $this->hasProjectPermission($environment->project, $permission);
    }

    /**
     * Check if user can view an environment.
     */
    public function canViewEnvironment(Environment $environment): bool
    {
        return $this->hasEnvironmentPermission($environment, 'view');
    }

    /**
     * Check if user can deploy to an environment.
     */
    public function canDeployToEnvironment(Environment $environment): bool
    {
        return $this->hasEnvironmentPermission($environment, 'deploy');
    }

    /**
     * Check if user can access secrets in an environment.
     */
    public function canAccessEnvironmentSecrets(Environment $environment): bool
    {
        return $this->hasEnvironmentPermission($environment, 'secrets');
    }

    /**
     * Grant user access to an environment with specified permissions.
     */
    public function grantEnvironmentAccess(Environment $environment, array $permissions = []): EnvironmentUser
    {
        $existingAccess = $this->getEnvironmentAccess($environment);

        if ($existingAccess) {
            $existingAccess->setPermissions($permissions)->save();

            return $existingAccess;
        }

        return EnvironmentUser::create([
            'user_id' => $this->id,
            'environment_id' => $environment->id,
            'permissions' => array_merge(EnvironmentUser::DEFAULT_PERMISSIONS, $permissions),
        ]);
    }

    /**
     * Revoke user access to an environment (will fall back to project cascade).
     */
    public function revokeEnvironmentAccess(Environment $environment): bool
    {
        return EnvironmentUser::where('user_id', $this->id)
            ->where('environment_id', $environment->id)
            ->delete() > 0;
    }

    /**
     * Get all projects the user can access in the current team.
     */
    public function getAccessibleProjectsInTeam(?int $teamId = null): \Illuminate\Support\Collection
    {
        $teamId = $teamId ?? $this->currentTeam()?->id;

        if (! $teamId) {
            return collect();
        }

        // Owners and admins see all projects in the team
        if ($this->isAdminOfTeam($teamId)) {
            return Project::where('team_id', $teamId)->get();
        }

        // Members and viewers only see projects they have explicit access to
        return $this->accessibleProjects()
            ->whereHas('team', fn ($q) => $q->where('id', $teamId))
            ->get();
    }
}
