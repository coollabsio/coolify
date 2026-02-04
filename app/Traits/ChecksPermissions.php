<?php

namespace App\Traits;

use App\Enums\Role;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait for checking permissions on resources.
 *
 * Provides a unified interface for permission checking across
 * different resource types in the application.
 */
trait ChecksPermissions
{
    /**
     * Check if granular permissions feature is enabled.
     */
    protected function isGranularPermissionsEnabled(): bool
    {
        return config('constants.features.granular_permissions', false);
    }

    /**
     * Check if user can perform an action on a resource.
     *
     * This is the main entry point for permission checking.
     * It handles the two-tier authorization model:
     * 1. Role-based bypass for owners/admins
     * 2. Granular permission checks for members/viewers
     */
    public function canPerform(string $action, Model $resource, ?int $teamId = null): bool
    {
        $teamId = $teamId ?? $this->currentTeam()?->id;

        if (! $teamId) {
            return false;
        }

        // Global admins can do anything
        if ($this->is_global_admin ?? false) {
            return true;
        }

        $role = $this->roleInTeam($teamId);

        if (! $role) {
            return false;
        }

        // Tier 1: Role-based bypass
        if ($this->shouldBypassPermissionCheck($role, $action)) {
            return true;
        }

        // If granular permissions are disabled, fall back to role-based access
        if (! $this->isGranularPermissionsEnabled()) {
            // Members have full access when granular permissions are disabled
            return $role !== Role::VIEWER->value;
        }

        // Tier 2: Granular permission check
        return $this->checkGranularPermission($action, $resource, $teamId);
    }

    /**
     * Determine if user's role should bypass permission checks.
     */
    protected function shouldBypassPermissionCheck(string $role, string $action): bool
    {
        // Owners bypass everything
        if ($role === Role::OWNER->value) {
            return true;
        }

        // Admins bypass most things except owner-only actions
        if ($role === Role::ADMIN->value) {
            $ownerOnlyActions = ['delete_team', 'transfer_ownership', 'promote_to_owner'];

            return ! in_array($action, $ownerOnlyActions);
        }

        return false;
    }

    /**
     * Check granular permission on a resource.
     */
    protected function checkGranularPermission(string $action, Model $resource, int $teamId): bool
    {
        // Map action to permission
        $permission = $this->mapActionToPermission($action);

        // Get the project associated with the resource
        $project = $this->getProjectForResource($resource);

        if (! $project) {
            // Resource is not associated with a project (e.g., team settings)
            // Fall back to team role check
            $role = $this->roleInTeam($teamId);

            return ! in_array($role, [Role::VIEWER->value, null]);
        }

        // Check project-level permission (cascades to environments and resources)
        return $this->hasProjectPermission($project, $permission);
    }

    /**
     * Map an action name to a permission name.
     */
    protected function mapActionToPermission(string $action): string
    {
        $actionMap = [
            // View actions
            'view' => 'view',
            'read' => 'view',
            'show' => 'view',
            'index' => 'view',
            'list' => 'view',

            // Deploy actions
            'deploy' => 'deploy',
            'redeploy' => 'deploy',
            'restart' => 'deploy',
            'start' => 'deploy',
            'stop' => 'deploy',

            // Manage actions
            'update' => 'manage',
            'edit' => 'manage',
            'configure' => 'manage',
            'manage' => 'manage',
            'create' => 'manage',

            // Delete actions
            'delete' => 'delete',
            'destroy' => 'delete',
            'remove' => 'delete',
            'force_delete' => 'delete',
        ];

        return $actionMap[$action] ?? 'view';
    }

    /**
     * Get the project associated with a resource.
     */
    protected function getProjectForResource(Model $resource): ?Project
    {
        // Direct project
        if ($resource instanceof Project) {
            return $resource;
        }

        // Environment -> Project
        if ($resource instanceof Environment) {
            return $resource->project;
        }

        // Application -> Environment -> Project
        if ($resource instanceof Application) {
            return $resource->environment?->project;
        }

        // Service -> Environment -> Project
        if ($resource instanceof Service) {
            return $resource->environment?->project;
        }

        // Standalone databases -> Environment -> Project
        if ($resource instanceof StandalonePostgresql ||
            $resource instanceof StandaloneMysql ||
            $resource instanceof StandaloneMariadb ||
            $resource instanceof StandaloneMongodb ||
            $resource instanceof StandaloneRedis) {
            return $resource->environment?->project;
        }

        // StandaloneDocker -> Environment -> Project
        if ($resource instanceof StandaloneDocker) {
            return $resource->environment?->project;
        }

        // Server -> Team (no project association, handle separately)
        if ($resource instanceof Server) {
            return null;
        }

        // Team -> No project
        if ($resource instanceof Team) {
            return null;
        }

        // Try to get project via relationship
        if (method_exists($resource, 'project')) {
            return $resource->project;
        }

        if (method_exists($resource, 'environment')) {
            return $resource->environment?->project;
        }

        return null;
    }

    /**
     * Check if user can view a resource.
     */
    public function canView(Model $resource): bool
    {
        return $this->canPerform('view', $resource);
    }

    /**
     * Check if user can update a resource.
     */
    public function canUpdate(Model $resource): bool
    {
        return $this->canPerform('update', $resource);
    }

    /**
     * Check if user can delete a resource.
     */
    public function canDelete(Model $resource): bool
    {
        return $this->canPerform('delete', $resource);
    }

    /**
     * Check if user can deploy a resource.
     */
    public function canDeploy(Model $resource): bool
    {
        return $this->canPerform('deploy', $resource);
    }

    /**
     * Check if user can create resources in a project.
     */
    public function canCreateIn(Project $project): bool
    {
        return $this->canPerform('create', $project);
    }

    /**
     * Check if user is a viewer (read-only).
     */
    public function isViewer(): bool
    {
        $role = $this->role();

        return $role === Role::VIEWER->value;
    }

    /**
     * Get the user's effective permissions for a project.
     */
    public function getEffectivePermissions(Project $project): array
    {
        // Owners and admins have all permissions
        if ($this->isOwner() || $this->isAdmin()) {
            return [
                'view' => true,
                'deploy' => true,
                'manage' => true,
                'delete' => true,
            ];
        }

        // Viewers have no permissions
        if ($this->isViewer()) {
            return [
                'view' => true,
                'deploy' => false,
                'manage' => false,
                'delete' => false,
            ];
        }

        // Members get permissions from project_user table
        $projectAccess = $this->getProjectAccess($project);

        if (! $projectAccess) {
            return [
                'view' => false,
                'deploy' => false,
                'manage' => false,
                'delete' => false,
            ];
        }

        return $projectAccess->permissions ?? [];
    }
}
