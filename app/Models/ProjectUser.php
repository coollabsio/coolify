<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * ProjectUser pivot model.
 *
 * Represents the many-to-many relationship between users and projects,
 * storing project-level permissions for each user.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property array $permissions
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\User $user
 */
class ProjectUser extends Pivot
{
    protected $table = 'project_user';

    public $incrementing = true;

    protected $fillable = [
        'project_id',
        'user_id',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * Default permissions structure.
     */
    public const DEFAULT_PERMISSIONS = [
        'view' => false,
        'deploy' => false,
        'manage' => false,
        'delete' => false,
    ];

    /**
     * Full access permissions.
     */
    public const FULL_ACCESS_PERMISSIONS = [
        'view' => true,
        'deploy' => true,
        'manage' => true,
        'delete' => true,
    ];

    /**
     * View-only permissions.
     */
    public const VIEW_ONLY_PERMISSIONS = [
        'view' => true,
        'deploy' => false,
        'manage' => false,
        'delete' => false,
    ];

    /**
     * Deploy permissions (view + deploy).
     */
    public const DEPLOY_PERMISSIONS = [
        'view' => true,
        'deploy' => true,
        'manage' => false,
        'delete' => false,
    ];

    /**
     * Get the project.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return $permissions[$permission] ?? false;
    }

    /**
     * Check if user can view the project.
     */
    public function canView(): bool
    {
        return $this->hasPermission('view');
    }

    /**
     * Check if user can deploy to the project.
     */
    public function canDeploy(): bool
    {
        return $this->hasPermission('deploy');
    }

    /**
     * Check if user can manage the project.
     */
    public function canManage(): bool
    {
        return $this->hasPermission('manage');
    }

    /**
     * Check if user can delete resources in the project.
     */
    public function canDelete(): bool
    {
        return $this->hasPermission('delete');
    }

    /**
     * Grant a specific permission.
     */
    public function grantPermission(string $permission): self
    {
        $permissions = $this->permissions ?? [];
        $permissions[$permission] = true;
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * Revoke a specific permission.
     */
    public function revokePermission(string $permission): self
    {
        $permissions = $this->permissions ?? [];
        $permissions[$permission] = false;
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * Set all permissions at once.
     */
    public function setPermissions(array $permissions): self
    {
        $this->permissions = array_merge(self::DEFAULT_PERMISSIONS, $permissions);

        return $this;
    }

    /**
     * Grant full access.
     */
    public function grantFullAccess(): self
    {
        $this->permissions = self::FULL_ACCESS_PERMISSIONS;

        return $this;
    }

    /**
     * Grant view-only access.
     */
    public function grantViewOnly(): self
    {
        $this->permissions = self::VIEW_ONLY_PERMISSIONS;

        return $this;
    }

    /**
     * Grant deploy access (view + deploy).
     */
    public function grantDeployAccess(): self
    {
        $this->permissions = self::DEPLOY_PERMISSIONS;

        return $this;
    }

    /**
     * Check if this is full access.
     */
    public function hasFullAccess(): bool
    {
        return $this->canView() && $this->canDeploy() && $this->canManage() && $this->canDelete();
    }

    /**
     * Check if this is view-only access.
     */
    public function isViewOnly(): bool
    {
        return $this->canView() && ! $this->canDeploy() && ! $this->canManage() && ! $this->canDelete();
    }
}
