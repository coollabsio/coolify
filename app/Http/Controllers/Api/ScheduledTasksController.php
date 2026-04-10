<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Service;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ScheduledTasksController extends Controller
{
    private function removeSensitiveData($task)
    {
        $task->makeHidden([
            'id',
            'team_id',
            'application_id',
            'service_id',
        ]);

        return serializeApiResponse($task);
    }

    private function resolveApplication(Request $request, int $teamId): ?Application
    {
        return Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
    }

    private function resolveService(Request $request, int $teamId): ?Service
    {
        return Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $request->uuid)->first();
    }

    private function listTasks(Application|Service $resource): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $resource);

        $tasks = $resource->scheduled_tasks->map(function ($task) {
            return $this->removeSensitiveData($task);
        });

        return response()->json($tasks);
    }

    private function createTask(Request $request, Application|Service $resource): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $resource);

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $allowedFields = ['name', 'command', 'frequency', 'container', 'timeout', 'enabled'];

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'command' => 'required|string',
            'frequency' => 'required|string',
            'container' => 'string|nullable',
            'timeout' => 'integer|min:1',
            'enabled' => 'boolean',
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

        if (! validate_cron_expression($request->frequency)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['frequency' => ['Invalid cron expression or frequency format.']],
            ], 422);
        }

        $teamId = getTeamIdFromToken();

        $task = new ScheduledTask;
        $task->name = $request->name;
        $task->command = $request->command;
        $task->frequency = $request->frequency;
        $task->container = $request->container;
        $task->timeout = $request->has('timeout') ? $request->timeout : 300;
        $task->enabled = $request->has('enabled') ? $request->enabled : true;
        $task->team_id = $teamId;

        if ($resource instanceof Application) {
            $task->application_id = $resource->id;
        } elseif ($resource instanceof Service) {
            $task->service_id = $resource->id;
        }

        $task->save();

        return response()->json($this->removeSensitiveData($task), 201);
    }

    private function updateTask(Request $request, Application|Service $resource): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $resource);

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        if ($request->all() === []) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        $allowedFields = ['name', 'command', 'frequency', 'container', 'timeout', 'enabled'];

        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'command' => 'string',
            'frequency' => 'string',
            'container' => 'string|nullable',
            'timeout' => 'integer|min:1',
            'enabled' => 'boolean',
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

        if ($request->has('frequency') && ! validate_cron_expression($request->frequency)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['frequency' => ['Invalid cron expression or frequency format.']],
            ], 422);
        }

        $task = $resource->scheduled_tasks()->where('uuid', $request->task_uuid)->first();
        if (! $task) {
            return response()->json(['message' => 'Scheduled task not found.'], 404);
        }

        $task->update($request->only($allowedFields));

        return response()->json($this->removeSensitiveData($task), 200);
    }

    private function deleteTask(Request $request, Application|Service $resource): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $resource);

        $deleted = $resource->scheduled_tasks()->where('uuid', $request->task_uuid)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'Scheduled task not found.'], 404);
        }

        return response()->json(['message' => 'Scheduled task deleted.']);
    }

    private function getExecutions(Request $request, Application|Service $resource): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $resource);

        $task = $resource->scheduled_tasks()->where('uuid', $request->task_uuid)->first();
        if (! $task) {
            return response()->json(['message' => 'Scheduled task not found.'], 404);
        }

        $executions = $task->executions()->get()->map(function ($execution) {
            $execution->makeHidden(['id', 'scheduled_task_id']);

            return serializeApiResponse($execution);
        });

        return response()->json($executions);
    }

    #[OA\Get(
        summary: 'List Tasks',
        description: 'List all scheduled tasks for an application.',
        path: '/applications/{uuid}/scheduled-tasks',
        operationId: 'list-scheduled-tasks-by-application-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all scheduled tasks for an application.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/ScheduledTask')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function scheduled_tasks_by_application_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = $this->resolveApplication($request, $teamId);
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->listTasks($application);
    }

    #[OA\Post(
        summary: 'Create Task',
        description: 'Create a new scheduled task for an application.',
        path: '/applications/{uuid}/scheduled-tasks',
        operationId: 'create-scheduled-task-by-application-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Scheduled task data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['name', 'command', 'frequency'],
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the scheduled task.'],
                        'command' => ['type' => 'string', 'description' => 'The command to execute.'],
                        'frequency' => ['type' => 'string', 'description' => 'The frequency of the scheduled task.'],
                        'container' => ['type' => 'string', 'nullable' => true, 'description' => 'The container where the command should be executed.'],
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled.', 'default' => true],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Scheduled task created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(ref: '#/components/schemas/ScheduledTask')
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
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
    public function create_scheduled_task_by_application_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = $this->resolveApplication($request, $teamId);
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->createTask($request, $application);
    }

    #[OA\Patch(
        summary: 'Update Task',
        description: 'Update a scheduled task for an application.',
        path: '/applications/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'update-scheduled-task-by-application-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'task_uuid',
                in: 'path',
                description: 'UUID of the scheduled task.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Scheduled task data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the scheduled task.'],
                        'command' => ['type' => 'string', 'description' => 'The command to execute.'],
                        'frequency' => ['type' => 'string', 'description' => 'The frequency of the scheduled task.'],
                        'container' => ['type' => 'string', 'nullable' => true, 'description' => 'The container where the command should be executed.'],
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled.', 'default' => true],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Scheduled task updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(ref: '#/components/schemas/ScheduledTask')
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
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
    public function update_scheduled_task_by_application_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = $this->resolveApplication($request, $teamId);
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->updateTask($request, $application);
    }

    #[OA\Delete(
        summary: 'Delete Task',
        description: 'Delete a scheduled task for an application.',
        path: '/applications/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'delete-scheduled-task-by-application-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'task_uuid',
                in: 'path',
                description: 'UUID of the scheduled task.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Scheduled task deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Scheduled task deleted.'],
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function delete_scheduled_task_by_application_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = $this->resolveApplication($request, $teamId);
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->deleteTask($request, $application);
    }

    #[OA\Get(
        summary: 'List Executions',
        description: 'List all executions for a scheduled task on an application.',
        path: '/applications/{uuid}/scheduled-tasks/{task_uuid}/executions',
        operationId: 'list-scheduled-task-executions-by-application-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'task_uuid',
                in: 'path',
                description: 'UUID of the scheduled task.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all executions for a scheduled task.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/ScheduledTaskExecution')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function executions_by_application_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = $this->resolveApplication($request, $teamId);
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->getExecutions($request, $application);
    }

    #[OA\Get(
        summary: 'List Tasks',
        description: 'List all scheduled tasks for a service.',
        path: '/services/{uuid}/scheduled-tasks',
        operationId: 'list-scheduled-tasks-by-service-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all scheduled tasks for a service.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/ScheduledTask')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function scheduled_tasks_by_service_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->listTasks($service);
    }

    #[OA\Post(
        summary: 'Create Task',
        description: 'Create a new scheduled task for a service.',
        path: '/services/{uuid}/scheduled-tasks',
        operationId: 'create-scheduled-task-by-service-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Scheduled task data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['name', 'command', 'frequency'],
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the scheduled task.'],
                        'command' => ['type' => 'string', 'description' => 'The command to execute.'],
                        'frequency' => ['type' => 'string', 'description' => 'The frequency of the scheduled task.'],
                        'container' => ['type' => 'string', 'nullable' => true, 'description' => 'The container where the command should be executed.'],
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled.', 'default' => true],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Scheduled task created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(ref: '#/components/schemas/ScheduledTask')
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
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
    public function create_scheduled_task_by_service_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->createTask($request, $service);
    }

    #[OA\Patch(
        summary: 'Update Task',
        description: 'Update a scheduled task for a service.',
        path: '/services/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'update-scheduled-task-by-service-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'task_uuid',
                in: 'path',
                description: 'UUID of the scheduled task.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Scheduled task data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the scheduled task.'],
                        'command' => ['type' => 'string', 'description' => 'The command to execute.'],
                        'frequency' => ['type' => 'string', 'description' => 'The frequency of the scheduled task.'],
                        'container' => ['type' => 'string', 'nullable' => true, 'description' => 'The container where the command should be executed.'],
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled.', 'default' => true],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Scheduled task updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(ref: '#/components/schemas/ScheduledTask')
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
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
    public function update_scheduled_task_by_service_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->updateTask($request, $service);
    }

    #[OA\Delete(
        summary: 'Delete Task',
        description: 'Delete a scheduled task for a service.',
        path: '/services/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'delete-scheduled-task-by-service-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'task_uuid',
                in: 'path',
                description: 'UUID of the scheduled task.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Scheduled task deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Scheduled task deleted.'],
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function delete_scheduled_task_by_service_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->deleteTask($request, $service);
    }

    #[OA\Get(
        summary: 'List Executions',
        description: 'List all executions for a scheduled task on a service.',
        path: '/services/{uuid}/scheduled-tasks/{task_uuid}/executions',
        operationId: 'list-scheduled-task-executions-by-service-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'task_uuid',
                in: 'path',
                description: 'UUID of the scheduled task.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all executions for a scheduled task.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/ScheduledTaskExecution')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function executions_by_service_uuid(Request $request): \Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->getExecutions($request, $service);
    }
}
