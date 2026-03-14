<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'ProjectMember model - represents a user with access to a specific project',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The unique identifier of the project member.'],
        'project_id' => ['type' => 'integer', 'description' => 'The ID of the project.'],
        'user_id' => ['type' => 'integer', 'description' => 'The ID of the user.'],
        'role' => ['type' => 'string', 'description' => 'The role of the member (viewer, editor, deployer).'],
        'can_deploy' => ['type' => 'boolean', 'description' => 'Whether the member can deploy apps.'],
        'created_at' => ['type' => 'string', 'description' => 'The date and time the member was added.'],
        'updated_at' => ['type' => 'string', 'description' => 'The date and time the member was last updated.'],
    ]
)]

class ProjectMember extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'can_deploy' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the member has a specific role or higher.
     */
    public function hasRole(string $role): bool
    {
        $roles = ['viewer' => 0, 'editor' => 1, 'deployer' => 2];
        $memberRoleLevel = $roles[$this->role] ?? 0;
        $requiredRoleLevel = $roles[$role] ?? 0;

        return $memberRoleLevel >= $requiredRoleLevel;
    }

    /**
     * Check if the member can deploy.
     */
    public function canDeploy(): bool
    {
        return $this->can_deploy || $this->hasRole('deployer');
    }
}
