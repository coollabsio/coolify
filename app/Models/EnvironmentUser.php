<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * EnvironmentUser pivot model.
 *
 * Represents the many-to-many relationship between users and environments,
 * allowing for environment-level permission overrides.
 *
 * By default, permissions cascade from the project level. This table
 * is only used when more granular control is needed.
 *
 * @property int $id
 * @property int $environment_id
 * @property int $user_id
 * @property array $permissions
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\Environment $environment
 * @property-read \App\Models\User $user
 */
class EnvironmentUser extends Pivot
{
    protected $table = 'environment_user';

    public $incrementing = true;

    protected $fillable = [
        'environment_id',
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
        'secrets' => false,
    ];

    /**
     * Full access permissions.
     */
    public const FULL_ACCESS_PERMISSIONS = [
        'view' => true,
        'deploy' => true,
        'secrets' => true,
    ];

    /**
     * View-only permissions.
     */
    public const VIEW_ONLY_PERMISSIONS = [
        'view' => true,
        'deploy' => false,
        'secrets' => false,
    ];

    /**
     * Deploy permissions (view + deploy).
     */
    public const DEPLOY_PERMISSIONS = [
        'view' => true,
        'deploy' => true,
        'secrets' => false,
    ];

    /**
     * Get the environment.
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
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
     * Check if user can view the environment.
     */
    public function canView(): bool
    {
        return $this->hasPermission('view');
    }

    /**
     * Check if user can deploy to the environment.
     */
    public function canDeploy(): bool
    {
        return $this->hasPermission('deploy');
    }

    /**
     * Check if user can view/edit secrets in the environment.
     */
    public function canAccessSecrets(): bool
    {
        return $this->hasPermission('secrets');
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
}
