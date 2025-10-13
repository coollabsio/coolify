<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ProjectController extends Controller
{
    #[OA\Get(
        summary: 'List',
        description: 'List projects.',
        path: '/projects',
        operationId: 'list-projects',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all projects.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Project')
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function projects(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $projects = Project::whereTeamId($teamId)->select('id', 'name', 'description', 'uuid')->get();

        return response()->json(serializeApiResponse($projects),
        );
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get project by UUID.',
        path: '/projects/{uuid}',
        operationId: 'get-project-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project details',
                content: new OA\JsonContent(ref: '#/components/schemas/Project')),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                description: 'Project not found.',
            ),
        ]
    )]
    public function project_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $project = Project::whereTeamId($teamId)->whereUuid(request()->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $project->load(['environments']);

        return response()->json(
            serializeApiResponse($project),
        );
    }

    #[OA\Get(
        summary: 'Environment',
        description: 'Get environment by name or UUID.',
        path: '/projects/{uuid}/{environment_name_or_uuid}',
        operationId: 'get-environment-by-name-or-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'environment_name_or_uuid', in: 'path', required: true, description: 'Environment name or UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Environment details',
                content: new OA\JsonContent(ref: '#/components/schemas/Environment')),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function environment_details(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 422);
        }
        if (! $request->environment_name_or_uuid) {
            return response()->json(['message' => 'Environment name or UUID is required.'], 422);
        }
        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->whereName($request->environment_name_or_uuid)->first();
        if (! $environment) {
            $environment = $project->environments()->whereUuid($request->environment_name_or_uuid)->first();
        }
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }
        $environment = $environment->load(['applications', 'postgresqls', 'redis', 'mongodbs', 'mysqls', 'mariadbs', 'services']);

        return response()->json(serializeApiResponse($environment));
    }

    #[OA\Post(
        summary: 'Create',
        description: 'Create Project.',
        path: '/projects',
        operationId: 'create-project',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Project created.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the project.'],
                        'description' => ['type' => 'string', 'description' => 'The description of the project.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Project created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the project.'],
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_project(Request $request)
    {
        $allowedFields = ['name', 'description'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ], ValidationPatterns::combinedMessages());

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

        $project = Project::create([
            'name' => $request->name,
            'description' => $request->description,
            'team_id' => $teamId,
        ]);

        return response()->json([
            'uuid' => $project->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update Project.',
        path: '/projects/{uuid}',
        operationId: 'update-project-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the project.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Project updated.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the project.'],
                        'description' => ['type' => 'string', 'description' => 'The description of the project.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Project updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'og888os'],
                                'name' => ['type' => 'string', 'example' => 'Project Name'],
                                'description' => ['type' => 'string', 'example' => 'Project Description'],
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_project(Request $request)
    {
        $allowedFields = ['name', 'description'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'name' => ValidationPatterns::nameRules(required: false),
            'description' => ValidationPatterns::descriptionRules(),
        ], ValidationPatterns::combinedMessages());

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
        $uuid = $request->uuid;
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $project->update($request->only($allowedFields));

        return response()->json([
            'uuid' => $project->uuid,
            'name' => $project->name,
            'description' => $project->description,
        ])->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete project by UUID.',
        path: '/projects/{uuid}',
        operationId: 'delete-project-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Project deleted.'],
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function delete_project(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 422);
        }
        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        if (! $project->isEmpty()) {
            return response()->json(['message' => 'Project has resources, so it cannot be deleted.'], 400);
        }

        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    #[OA\Get(
        summary: 'List Environments',
        description: 'List all environments in a project.',
        path: '/projects/{uuid}/environments',
        operationId: 'get-environments',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of environments',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Environment')
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                description: 'Project not found.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function get_environments(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'Project UUID is required.'], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $environments = $project->environments()->select('id', 'name', 'uuid')->get();

        return response()->json(serializeApiResponse($environments));
    }

    #[OA\Post(
        summary: 'Create Environment',
        description: 'Create environment in project.',
        path: '/projects/{uuid}/environments',
        operationId: 'create-environment',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Environment created.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the environment.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'env123', 'description' => 'The UUID of the environment.'],
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                description: 'Project not found.',
            ),
            new OA\Response(
                response: 409,
                description: 'Environment with this name already exists.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_environment(Request $request)
    {
        $allowedFields = ['name'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'name' => ValidationPatterns::nameRules(),
        ], ValidationPatterns::nameMessages());

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

        if (! $request->uuid) {
            return response()->json(['message' => 'Project UUID is required.'], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $existingEnvironment = $project->environments()->where('name', $request->name)->first();
        if ($existingEnvironment) {
            return response()->json(['message' => 'Environment with this name already exists.'], 409);
        }

        $environment = $project->environments()->create([
            'name' => $request->name,
        ]);

        return response()->json([
            'uuid' => $environment->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete Environment',
        description: 'Delete environment by name or UUID. Environment must be empty.',
        path: '/projects/{uuid}/environments/{environment_name_or_uuid}',
        operationId: 'delete-environment',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'environment_name_or_uuid', in: 'path', required: true, description: 'Environment name or UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Environment deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment deleted.'],
                            ]
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                description: 'Environment has resources, so it cannot be deleted.',
            ),
            new OA\Response(
                response: 404,
                description: 'Project or environment not found.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function delete_environment(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'Project UUID is required.'], 422);
        }
        if (! $request->environment_name_or_uuid) {
            return response()->json(['message' => 'Environment name or UUID is required.'], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $environment = $project->environments()->whereName($request->environment_name_or_uuid)->first();
        if (! $environment) {
            $environment = $project->environments()->whereUuid($request->environment_name_or_uuid)->first();
        }
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }

        if (! $environment->isEmpty()) {
            return response()->json(['message' => 'Environment has resources, so it cannot be deleted.'], 400);
        }

        $environment->delete();

        return response()->json(['message' => 'Environment deleted.']);
    }
}
