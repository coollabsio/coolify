<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class PermissionsController extends Controller
{
    #[OA\Get(
        summary: 'List Project Access',
        description: 'Get all users with access to a project.',
        path: '/projects/{uuid}/access',
        operationId: 'list-project-access',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of users with project access.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'user_id', type: 'integer'),
                                    new OA\Property(property: 'user_email', type: 'string'),
                                    new OA\Property(property: 'user_name', type: 'string'),
                                    new OA\Property(property: 'permissions', type: 'object'),
                                ]
                            )
                        )
                    ),
                ]
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function listProjectAccess(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $access = ProjectUser::where('project_id', $project->id)
            ->with('user:id,name,email')
            ->get()
            ->map(fn ($pu) => [
                'id' => $pu->id,
                'user_id' => $pu->user_id,
                'user_name' => $pu->user->name,
                'user_email' => $pu->user->email,
                'permissions' => $pu->permissions,
            ]);

        return response()->json(serializeApiResponse($access));
    }

    #[OA\Post(
        summary: 'Grant Project Access',
        description: 'Grant a user access to a project.',
        path: '/projects/{uuid}/access',
        operationId: 'grant-project-access',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', description: 'User ID to grant access'),
                    new OA\Property(
                        property: 'permission_level',
                        type: 'string',
                        enum: ['view_only', 'deploy', 'full_access'],
                        default: 'view_only'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Access granted successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'id', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function grantProjectAccess(Request $request)
    {
        $allowedFields = ['user_id', 'permission_level'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'permission_level' => ['sometimes', 'string', 'in:view_only,deploy,full_access'],
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $user = User::find($request->user_id);

        // Check if user is part of the team
        if (! $user->teams()->where('teams.id', $teamId)->exists()) {
            return response()->json(['message' => 'User is not a member of this team.'], 400);
        }

        // Check if already has access
        $existing = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'User already has access to this project.'], 400);
        }

        $permissionLevel = $request->permission_level ?? 'view_only';

        $projectUser = ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'permissions' => ProjectUser::getPermissionsForLevel($permissionLevel),
        ]);

        return response()->json([
            'message' => 'Access granted successfully.',
            'id' => $projectUser->id,
        ], 201);
    }

    #[OA\Patch(
        summary: 'Update Project Access',
        description: 'Update user permissions on a project.',
        path: '/projects/{uuid}/access/{user_id}',
        operationId: 'update-project-access',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['permission_level'],
                properties: [
                    new OA\Property(
                        property: 'permission_level',
                        type: 'string',
                        enum: ['view_only', 'deploy', 'full_access']
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Access updated successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function updateProjectAccess(Request $request)
    {
        $allowedFields = ['permission_level'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = Validator::make($request->all(), [
            'permission_level' => ['required', 'string', 'in:view_only,deploy,full_access'],
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $projectUser = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $request->user_id)
            ->first();

        if (! $projectUser) {
            return response()->json(['message' => 'User access not found.'], 404);
        }

        $projectUser->setPermissions(ProjectUser::getPermissionsForLevel($request->permission_level))->save();

        return response()->json(['message' => 'Access updated successfully.']);
    }

    #[OA\Delete(
        summary: 'Revoke Project Access',
        description: 'Revoke user access to a project.',
        path: '/projects/{uuid}/access/{user_id}',
        operationId: 'revoke-project-access',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Access revoked successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function revokeProjectAccess(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $deleted = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $request->user_id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'User access not found.'], 404);
        }

        return response()->json(['message' => 'Access revoked successfully.']);
    }

    #[OA\Get(
        summary: 'Check User Permission',
        description: 'Check if a user has a specific permission on a project.',
        path: '/projects/{uuid}/access/{user_id}/check',
        operationId: 'check-project-permission',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'permission', in: 'query', required: true, description: 'Permission to check', schema: new OA\Schema(type: 'string', enum: ['view', 'deploy', 'manage', 'delete'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permission check result.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'has_permission', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function checkPermission(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $permission = $request->query('permission');
        if (! in_array($permission, ['view', 'deploy', 'manage', 'delete'])) {
            return response()->json(['message' => 'Invalid permission type.'], 400);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $user = User::find($request->user_id);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Check if user is in the team
        if (! $user->teams()->where('teams.id', $teamId)->exists()) {
            return response()->json(['message' => 'User is not a member of this team.'], 400);
        }

        $hasPermission = $user->hasProjectPermission($project, $permission);

        return response()->json(['has_permission' => $hasPermission]);
    }
}
