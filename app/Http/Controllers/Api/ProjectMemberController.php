<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectMemberRole;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Visus\Cuid2\Cuid2;

class ProjectMemberController extends Controller
{
    #[OA\Get(
        summary: 'List Project Members',
        description: 'List all project-specific members for a project.',
        path: '/projects/{uuid}/members',
        operationId: 'list-project-members',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of project members.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function list_members(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $members = ProjectMember::where('project_id', $project->id)
            ->with('user:id,name,email')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role->value,
                'permissions' => $m->permissions,
                'accepted_at' => $m->accepted_at,
                'created_at' => $m->created_at,
            ]);

        return response()->json(serializeApiResponse($members));
    }

    #[OA\Post(
        summary: 'Invite Project Member',
        description: 'Invite a user as a project-specific member.',
        path: '/projects/{uuid}/members',
        operationId: 'invite-project-member',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'email' => ['type' => 'string', 'description' => 'Email of the user to invite.'],
                        'role' => ['type' => 'string', 'enum' => ['viewer', 'deployer', 'manager'], 'description' => 'Role for the project member.'],
                    ],
                    required: ['email', 'role'],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Member invited.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function invite_member(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'role' => 'required|string|in:viewer,deployer,manager',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $email = strtolower($request->email);

        // Check if already a member
        $existing = ProjectMember::where('project_id', $project->id)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->first();

        if ($existing) {
            return response()->json(['message' => 'User is already a project member.'], 409);
        }

        // Create or find user
        $user = User::where('email', $email)->first();
        if (! $user) {
            $password = Str::password();
            $user = User::create([
                'name' => str($email)->before('@'),
                'email' => $email,
                'password' => Hash::make($password),
                'force_password_reset' => true,
            ]);
        }

        // Add user to team if not already there
        if (! $user->teams()->where('team_id', $teamId)->exists()) {
            $user->teams()->attach($teamId, ['role' => 'member']);
        }

        // Create project membership
        $member = ProjectMember::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'role' => $request->role,
            'invited_by' => auth()->id(),
            'accepted_at' => now(),
        ]);

        return response()->json([
            'id' => $member->id,
            'message' => 'Member added to project.',
        ])->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update Project Member Role',
        description: 'Update a project member\'s role.',
        path: '/projects/{uuid}/members/{member_id}',
        operationId: 'update-project-member',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, description: 'Project Member ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'role' => ['type' => 'string', 'enum' => ['viewer', 'deployer', 'manager']],
                    ],
                    required: ['role'],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Member updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_member(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'required|string|in:viewer,deployer,manager',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $member = ProjectMember::where('project_id', $project->id)
            ->where('id', $request->member_id)
            ->first();

        if (! $member) {
            return response()->json(['message' => 'Project member not found.'], 404);
        }

        $member->update(['role' => $request->role]);

        return response()->json(['message' => 'Member role updated.']);
    }

    #[OA\Delete(
        summary: 'Remove Project Member',
        description: 'Remove a member from a project.',
        path: '/projects/{uuid}/members/{member_id}',
        operationId: 'remove-project-member',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, description: 'Project Member ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Member removed.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function remove_member(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $member = ProjectMember::where('project_id', $project->id)
            ->where('id', $request->member_id)
            ->first();

        if (! $member) {
            return response()->json(['message' => 'Project member not found.'], 404);
        }

        $member->delete();

        return response()->json(['message' => 'Member removed from project.']);
    }
}
