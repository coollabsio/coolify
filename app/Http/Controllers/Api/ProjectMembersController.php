<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Project Members API controller',
    type: 'object',
)]

class ProjectMembersController extends Controller
{
    /**
     * Get all members of a project.
     */
    #[OA\Get(
        path: '/v1/projects/{uuid}/members',
        summary: 'Get project members',
        description: 'Get all members of a specific project',
        tags: ['Project Members'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Project members',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ProjectMember')
        )
    )]
    public function index(string $uuid): JsonResponse
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        
        // Check if user has access to the project
        $user = auth()->user();
        if (!$this->userCanAccessProject($user, $project)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $members = $project->members()->with('user')->get();
        
        return response()->json($members);
    }

    /**
     * Add a member to a project.
     */
    #[OA\Post(
        path: '/v1/projects/{uuid}/members',
        summary: 'Add project member',
        description: 'Add a user as a member to a project',
        tags: ['Project Members'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'role', type: 'string', enum: ['viewer', 'editor', 'deployer']),
                    new OA\Property(property: 'can_deploy', type: 'boolean'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Member added',
    )]
    public function store(Request $request, string $uuid): JsonResponse
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        
        // Only team admins/owners can add project members
        $user = auth()->user();
        if (!$this->userIsTeamAdmin($user, $project)) {
            return response()->json(['error' => 'Unauthorized. Only team admins can add project members.'], 403);
        }

        $request->validate([
            'email' => 'required|email|exists:users,email',
            'role' => 'nullable|in:viewer,editor,deployer',
            'can_deploy' => 'nullable|boolean',
        ]);

        $memberUser = User::where('email', $request->email)->firstOrFail();

        // Check if user is already a member
        $existingMember = $project->members()->where('user_id', $memberUser->id)->first();
        if ($existingMember) {
            return response()->json(['error' => 'User is already a member of this project'], 400);
        }

        $projectMember = ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $memberUser->id,
            'role' => $request->role ?? 'viewer',
            'can_deploy' => $request->can_deploy ?? false,
        ]);

        return response()->json($projectMember->load('user'), 201);
    }

    /**
     * Update a project member.
     */
    #[OA\Patch(
        path: '/v1/projects/{uuid}/members/{member_id}',
        summary: 'Update project member',
        description: 'Update a project member\'s role and permissions',
        tags: ['Project Members'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'role', type: 'string', enum: ['viewer', 'editor', 'deployer']),
                    new OA\Property(property: 'can_deploy', type: 'boolean'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Member updated',
    )]
    public function update(Request $request, string $uuid, int $memberId): JsonResponse
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        
        // Only team admins/owners can update project members
        $user = auth()->user();
        if (!$this->userIsTeamAdmin($user, $project)) {
            return response()->json(['error' => 'Unauthorized. Only team admins can update project members.'], 403);
        }

        $projectMember = ProjectMember::where('id', $memberId)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $request->validate([
            'role' => 'nullable|in:viewer,editor,deployer',
            'can_deploy' => 'nullable|boolean',
        ]);

        if ($request->has('role')) {
            $projectMember->role = $request->role;
        }
        if ($request->has('can_deploy')) {
            $projectMember->can_deploy = $request->can_deploy;
        }

        $projectMember->save();

        return response()->json($projectMember->load('user'));
    }

    /**
     * Remove a member from a project.
     */
    #[OA\Delete(
        path: '/v1/projects/{uuid}/members/{member_id}',
        summary: 'Remove project member',
        description: 'Remove a user from a project',
        tags: ['Project Members'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ]
    )]
    #[OA\Response(
        response: 204,
        description: 'Member removed',
    )]
    public function destroy(string $uuid, int $memberId): JsonResponse
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        
        // Only team admins/owners can remove project members
        $user = auth()->user();
        if (!$this->userIsTeamAdmin($user, $project)) {
            return response()->json(['error' => 'Unauthorized. Only team admins can remove project members.'], 403);
        }

        $projectMember = ProjectMember::where('id', $memberId)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $projectMember->delete();

        return response()->json(null, 204);
    }

    /**
     * Check if user can access the project (as team member or project member).
     */
    private function userCanAccessProject(User $user, Project $project): bool
    {
        // Team members have access to all projects
        if ($user->teams()->where('team_id', $project->team_id)->exists()) {
            return true;
        }

        // Project-specific members have access
        if ($project->members()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Check if user is a team admin or owner.
     */
    private function userIsTeamAdmin(User $user, Project $project): bool
    {
        $membership = $user->teams()->where('team_id', $project->team_id)->first();
        
        if (!$membership) {
            return false;
        }

        return in_array($membership->pivot->role, ['admin', 'owner']);
    }
}
