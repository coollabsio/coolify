<?php

namespace App\Models;

use App\Enums\ProjectMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'role',
        'permissions',
        'invited_by',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'accepted_at' => 'datetime',
            'role' => ProjectMemberRole::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function canDeploy(): bool
    {
        return $this->role->canDeploy();
    }

    public function canManage(): bool
    {
        return $this->role->canManage();
    }

    /**
     * Check if this project member has a specific permission.
     * Permissions JSON can override the role-based defaults.
     */
    public function hasPermission(string $permission): bool
    {
        // Check explicit permissions first
        if (is_array($this->permissions) && array_key_exists($permission, $this->permissions)) {
            return (bool) $this->permissions[$permission];
        }

        // Fall back to role-based permissions
        return match ($permission) {
            'view' => true,
            'deploy' => $this->canDeploy(),
            'manage' => $this->canManage(),
            default => false,
        };
    }

    /**
     * Scope to get members for a specific project.
     */
    public static function forProject(int $projectId): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('project_id', $projectId);
    }
}
