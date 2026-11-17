<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Api\Concerns\RequiresDeployAbility;
use App\Http\Controllers\Controller;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\NodeWorkload;
use App\Models\ScheduledTask;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class ScheduledTasksController extends Controller
{
    use HandlesClusterResourcesApi;
    use RequiresDeployAbility;

    private function removeSensitiveData($task)
    {
        $task->makeHidden([
            'id',
            'team_id',
            'application_id',
            'service_id',
            'node_workload_id',
        ]);
        if (! $this->canReadSensitive()) {
            $task->makeHidden(['command']);
        }

        return serializeApiResponse($task);
    }

    private function canReadSensitive(): bool
    {
        return request()->attributes->get('can_read_sensitive', false) === true
            && ($this->clusterResource === null || Gate::allows('viewSensitive', $this->clusterResource));
    }

    /** The cluster workload of the current request, for canReadSensitive(). */
    private ?NodeWorkload $clusterResource = null;

    private function resourceType(Application|Service|NodeWorkload $resource): string
    {
        return match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'cluster-application',
        };
    }

    /**
     * The scheduler runs an enabled task's command in the resource's containers, so the token needs the deploy permission.
     */
    private function enabledTaskForbiddenResponse(Request $request, bool $enabled): ?JsonResponse
    {
        if (! $enabled) {
            return null;
        }

        return $this->deployAbilityForbiddenResponse($request, 'An enabled scheduled task runs commands in the resource containers, so it needs a token with the deploy permission.');
    }

    private function resolveApplication(Request $request, int $teamId): ?Application
    {
        return Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
    }

    private function resolveService(Request $request, int $teamId): ?Service
    {
        return Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $request->uuid)->first();
    }

    private function listTasks(Application|Service|NodeWorkload $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $tasks = $resource->scheduled_tasks->map(function ($task) {
            return $this->removeSensitiveData($task);
        });

        return response()->json($tasks);
    }

    private function createTask(Request $request, Application|Service|NodeWorkload $resource): JsonResponse
    {
        $this->authorize('update', $resource);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = ['name', 'command', 'frequency', 'container', 'timeout', 'use_shell', 'enabled'];

        $validator = customApiValidator($request->all(), [
            'name' => 'required|string|max:255',
            'command' => 'required|string',
            'frequency' => 'required|string',
            'container' => 'string|nullable',
            'timeout' => 'integer|min:1|max:36000',
            'use_shell' => 'boolean',
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

        $enabled = $request->has('enabled') ? $request->boolean('enabled') : true;
        if ($response = $this->enabledTaskForbiddenResponse($request, $enabled)) {
            return $response;
        }

        $teamId = getTeamIdFromToken();

        $task = new ScheduledTask;
        $task->name = $request->name;
        $task->command = $request->command;
        $task->frequency = $request->frequency;
        $task->container = $request->container;
        $task->timeout = $request->has('timeout') ? $request->timeout : 300;
        $task->use_shell = $request->has('use_shell') ? $request->boolean('use_shell') : true;
        $task->enabled = $enabled;
        $task->team_id = $teamId;

        if ($resource instanceof Application) {
            $task->application_id = $resource->id;
        } elseif ($resource instanceof Service) {
            $task->service_id = $resource->id;
        } else {
            $task->node_workload_id = $resource->id;
        }

        $task->save();

        auditLog('api.scheduled_task.created', [
            'team_id' => $teamId,
            'task_uuid' => $task->uuid,
            'task_name' => $task->name,
            'resource_type' => $this->resourceType($resource),
            'resource_uuid' => $resource->uuid,
        ]);

        return response()->json($this->removeSensitiveData($task), 201);
    }

    private function updateTask(Request $request, Application|Service|NodeWorkload $resource): JsonResponse
    {
        $this->authorize('update', $resource);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        if ($request->all() === []) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        $allowedFields = ['name', 'command', 'frequency', 'container', 'timeout', 'use_shell', 'enabled'];

        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'command' => 'string',
            'frequency' => 'string',
            'container' => 'string|nullable',
            'timeout' => 'integer|min:1|max:36000',
            'use_shell' => 'boolean',
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

        $enabled = $request->has('enabled') ? $request->boolean('enabled') : $task->enabled;
        if ($response = $this->enabledTaskForbiddenResponse($request, $enabled)) {
            return $response;
        }

        $task->fill($request->only($allowedFields));
        $changedFields = auditChangedFields($task);
        $task->save();

        if ($changedFields === []) {
            return response()->json($this->removeSensitiveData($task), 200);
        }

        auditLog('api.scheduled_task.updated', [
            'team_id' => getTeamIdFromToken(),
            'task_uuid' => $task->uuid,
            'task_name' => $task->name,
            'resource_type' => $this->resourceType($resource),
            'resource_uuid' => $resource->uuid,
            'changed_fields' => $changedFields,
        ]);

        return response()->json($this->removeSensitiveData($task), 200);
    }

    private function deleteTask(Request $request, Application|Service|NodeWorkload $resource): JsonResponse
    {
        $this->authorize('delete', $resource);

        $task = $resource->scheduled_tasks()->where('uuid', $request->task_uuid)->first();
        if (! $task) {
            return response()->json(['message' => 'Scheduled task not found.'], 404);
        }

        $taskUuid = $task->uuid;
        $taskName = $task->name;
        $task->delete();

        auditLog('api.scheduled_task.deleted', [
            'team_id' => getTeamIdFromToken(),
            'task_uuid' => $taskUuid,
            'task_name' => $taskName,
            'resource_type' => $this->resourceType($resource),
            'resource_uuid' => $resource->uuid,
        ]);

        return response()->json(['message' => 'Scheduled task deleted.']);
    }

    private function getExecutions(Request $request, Application|Service|NodeWorkload $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $task = $resource->scheduled_tasks()->where('uuid', $request->task_uuid)->first();
        if (! $task) {
            return response()->json(['message' => 'Scheduled task not found.'], 404);
        }

        $executions = $task->executions()->get()->map(function ($execution) {
            $execution->makeHidden(['id', 'scheduled_task_id']);
            if (! $this->canReadSensitive()) {
                $execution->makeHidden(['message']);
            }

            return serializeApiResponse($execution);
        });

        return response()->json($executions);
    }

    private function executeTask(Request $request, Application|Service|NodeWorkload $resource): JsonResponse
    {
        $this->authorize('update', $resource);

        $task = $resource->scheduled_tasks()->where('uuid', $request->task_uuid)->first();
        if (! $task) {
            return response()->json(['message' => 'Scheduled task not found.'], 404);
        }

        ScheduledTaskJob::dispatch($task);

        auditLog('api.scheduled_task.executed', [
            'team_id' => getTeamIdFromToken(),
            'task_uuid' => $task->uuid,
            'task_name' => $task->name,
            'resource_type' => $this->resourceType($resource),
            'resource_uuid' => $resource->uuid,
        ]);

        return response()->json(['message' => 'Scheduled task execution queued.']);
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
    public function scheduled_tasks_by_application_uuid(Request $request): JsonResponse
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
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300, 'maximum' => 36000],
                        'use_shell' => ['type' => 'boolean', 'description' => 'Run the command with `sh -c` in the container. Set it to false for containers without a shell: the command is then split into arguments and run directly, without shell features such as variables, pipes, or redirects.', 'default' => true],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled. An enabled task requires the deploy ability.', 'default' => true],
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
                response: 403,
                description: 'The token needs the deploy ability for an enabled task.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_scheduled_task_by_application_uuid(Request $request): JsonResponse
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
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300, 'maximum' => 36000],
                        'use_shell' => ['type' => 'boolean', 'description' => 'Run the command with `sh -c` in the container. Set it to false for containers without a shell: the command is then split into arguments and run directly, without shell features such as variables, pipes, or redirects.', 'default' => true],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled. An enabled task requires the deploy ability.', 'default' => true],
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
                response: 403,
                description: 'The token needs the deploy ability for an enabled task.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_scheduled_task_by_application_uuid(Request $request): JsonResponse
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
    public function delete_scheduled_task_by_application_uuid(Request $request): JsonResponse
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
    public function executions_by_application_uuid(Request $request): JsonResponse
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
    public function scheduled_tasks_by_service_uuid(Request $request): JsonResponse
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
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300, 'maximum' => 36000],
                        'use_shell' => ['type' => 'boolean', 'description' => 'Run the command with `sh -c` in the container. Set it to false for containers without a shell: the command is then split into arguments and run directly, without shell features such as variables, pipes, or redirects.', 'default' => true],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled. An enabled task requires the deploy ability.', 'default' => true],
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
                response: 403,
                description: 'The token needs the deploy ability for an enabled task.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_scheduled_task_by_service_uuid(Request $request): JsonResponse
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
                        'timeout' => ['type' => 'integer', 'description' => 'The timeout of the scheduled task in seconds.', 'default' => 300, 'maximum' => 36000],
                        'use_shell' => ['type' => 'boolean', 'description' => 'Run the command with `sh -c` in the container. Set it to false for containers without a shell: the command is then split into arguments and run directly, without shell features such as variables, pipes, or redirects.', 'default' => true],
                        'enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if the scheduled task is enabled. An enabled task requires the deploy ability.', 'default' => true],
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
                response: 403,
                description: 'The token needs the deploy ability for an enabled task.',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_scheduled_task_by_service_uuid(Request $request): JsonResponse
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
    public function delete_scheduled_task_by_service_uuid(Request $request): JsonResponse
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
    public function executions_by_service_uuid(Request $request): JsonResponse
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

    #[OA\Post(
        summary: 'Execute Task',
        description: 'Queue immediate execution of a scheduled task for an application. Requires the deploy ability.',
        path: '/applications/{uuid}/scheduled-tasks/{task_uuid}/execute',
        operationId: 'execute-scheduled-task-by-application-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scheduled task execution queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy ability.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function execute_scheduled_task_by_application_uuid(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = $this->resolveApplication($request, $teamId);
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->executeTask($request, $application);
    }

    #[OA\Post(
        summary: 'Execute Task',
        description: 'Queue immediate execution of a scheduled task for a service. Requires the deploy ability.',
        path: '/services/{uuid}/scheduled-tasks/{task_uuid}/execute',
        operationId: 'execute-scheduled-task-by-service-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Scheduled Tasks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the service.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scheduled task execution queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy ability.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function execute_scheduled_task_by_service_uuid(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->executeTask($request, $service);
    }

    #[OA\Get(
        summary: 'List Tasks',
        description: 'List the scheduled tasks of a cluster application. `command` shows only for a token that may read sensitive data.',
        path: '/cluster-applications/{uuid}/scheduled-tasks',
        operationId: 'list-scheduled-tasks-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The scheduled tasks.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScheduledTask'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Tasks',
        description: 'List the scheduled tasks of a cluster database. `command` shows only for a token that may read sensitive data.',
        path: '/cluster-databases/{uuid}/scheduled-tasks',
        operationId: 'list-scheduled-tasks-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The scheduled tasks.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScheduledTask'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Tasks',
        description: 'List the scheduled tasks of a cluster stack. `command` shows only for a token that may read sensitive data.',
        path: '/cluster-stacks/{uuid}/scheduled-tasks',
        operationId: 'list-scheduled-tasks-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The scheduled tasks.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScheduledTask'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function clusterTasks(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->clusterResource = $workload;

        return $this->listTasks($workload);
    }

    #[OA\Post(
        summary: 'Create Task',
        description: 'Create a scheduled task of a cluster application. It runs the command in the container of the workload.',
        path: '/cluster-applications/{uuid}/scheduled-tasks',
        operationId: 'create-scheduled-task-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'container', type: 'string', nullable: true, description: 'Not used: a cluster workload has one container.'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds. The default is 300.'),
            new OA\Property(property: 'use_shell', type: 'boolean'),
            new OA\Property(property: 'enabled', type: 'boolean', description: 'An enabled task needs a token with the deploy permission.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The scheduled task.', content: new OA\JsonContent(ref: '#/components/schemas/ScheduledTask')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Task',
        description: 'Create a scheduled task of a cluster database. It runs the command in the container of the workload.',
        path: '/cluster-databases/{uuid}/scheduled-tasks',
        operationId: 'create-scheduled-task-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'container', type: 'string', nullable: true, description: 'Not used: a cluster workload has one container.'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds. The default is 300.'),
            new OA\Property(property: 'use_shell', type: 'boolean'),
            new OA\Property(property: 'enabled', type: 'boolean', description: 'An enabled task needs a token with the deploy permission.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The scheduled task.', content: new OA\JsonContent(ref: '#/components/schemas/ScheduledTask')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Task',
        description: 'Create a scheduled task of a cluster stack. It runs the command in the container of the workload.',
        path: '/cluster-stacks/{uuid}/scheduled-tasks',
        operationId: 'create-scheduled-task-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'container', type: 'string', nullable: true, description: 'Not used: a cluster workload has one container.'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds. The default is 300.'),
            new OA\Property(property: 'use_shell', type: 'boolean'),
            new OA\Property(property: 'enabled', type: 'boolean', description: 'An enabled task needs a token with the deploy permission.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The scheduled task.', content: new OA\JsonContent(ref: '#/components/schemas/ScheduledTask')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createClusterTask(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->clusterResource = $workload;

        return $this->createTask($request, $workload);
    }

    #[OA\Patch(
        summary: 'Update Task',
        description: 'Update a scheduled task of a cluster application.',
        path: '/cluster-applications/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'update-scheduled-task-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'container', type: 'string', nullable: true, description: 'Not used: a cluster workload has one container.'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds. The default is 300.'),
            new OA\Property(property: 'use_shell', type: 'boolean'),
            new OA\Property(property: 'enabled', type: 'boolean', description: 'An enabled task needs a token with the deploy permission.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The scheduled task.', content: new OA\JsonContent(ref: '#/components/schemas/ScheduledTask')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Task',
        description: 'Update a scheduled task of a cluster database.',
        path: '/cluster-databases/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'update-scheduled-task-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'container', type: 'string', nullable: true, description: 'Not used: a cluster workload has one container.'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds. The default is 300.'),
            new OA\Property(property: 'use_shell', type: 'boolean'),
            new OA\Property(property: 'enabled', type: 'boolean', description: 'An enabled task needs a token with the deploy permission.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The scheduled task.', content: new OA\JsonContent(ref: '#/components/schemas/ScheduledTask')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Task',
        description: 'Update a scheduled task of a cluster stack.',
        path: '/cluster-stacks/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'update-scheduled-task-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'container', type: 'string', nullable: true, description: 'Not used: a cluster workload has one container.'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds. The default is 300.'),
            new OA\Property(property: 'use_shell', type: 'boolean'),
            new OA\Property(property: 'enabled', type: 'boolean', description: 'An enabled task needs a token with the deploy permission.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The scheduled task.', content: new OA\JsonContent(ref: '#/components/schemas/ScheduledTask')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function updateClusterTask(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->clusterResource = $workload;

        return $this->updateTask($request, $workload);
    }

    #[OA\Delete(
        summary: 'Delete Task',
        description: 'Delete a scheduled task of a cluster application.',
        path: '/cluster-applications/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'delete-scheduled-task-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The scheduled task is deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Task',
        description: 'Delete a scheduled task of a cluster database.',
        path: '/cluster-databases/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'delete-scheduled-task-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The scheduled task is deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Task',
        description: 'Delete a scheduled task of a cluster stack.',
        path: '/cluster-stacks/{uuid}/scheduled-tasks/{task_uuid}',
        operationId: 'delete-scheduled-task-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The scheduled task is deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deleteClusterTask(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->clusterResource = $workload;

        return $this->deleteTask($request, $workload);
    }

    #[OA\Get(
        summary: 'List Executions',
        description: 'List the runs of a scheduled task of a cluster application. `message` (the output) shows only for a token that may read sensitive data.',
        path: '/cluster-applications/{uuid}/scheduled-tasks/{task_uuid}/executions',
        operationId: 'list-scheduled-task-executions-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScheduledTaskExecution'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Executions',
        description: 'List the runs of a scheduled task of a cluster database. `message` (the output) shows only for a token that may read sensitive data.',
        path: '/cluster-databases/{uuid}/scheduled-tasks/{task_uuid}/executions',
        operationId: 'list-scheduled-task-executions-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScheduledTaskExecution'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Executions',
        description: 'List the runs of a scheduled task of a cluster stack. `message` (the output) shows only for a token that may read sensitive data.',
        path: '/cluster-stacks/{uuid}/scheduled-tasks/{task_uuid}/executions',
        operationId: 'list-scheduled-task-executions-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScheduledTaskExecution'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function clusterTaskExecutions(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->clusterResource = $workload;

        return $this->getExecutions($request, $workload);
    }

    #[OA\Post(
        summary: 'Execute Task',
        description: 'Queue a run of a scheduled task of a cluster application now. Requires the deploy ability.',
        path: '/cluster-applications/{uuid}/scheduled-tasks/{task_uuid}/execute',
        operationId: 'execute-scheduled-task-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The run is queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Post(
        summary: 'Execute Task',
        description: 'Queue a run of a scheduled task of a cluster database now. Requires the deploy ability.',
        path: '/cluster-databases/{uuid}/scheduled-tasks/{task_uuid}/execute',
        operationId: 'execute-scheduled-task-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The run is queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Post(
        summary: 'Execute Task',
        description: 'Queue a run of a scheduled task of a cluster stack now. Requires the deploy ability.',
        path: '/cluster-stacks/{uuid}/scheduled-tasks/{task_uuid}/execute',
        operationId: 'execute-scheduled-task-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'task_uuid', in: 'path', required: true, description: 'UUID of the scheduled task.', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The run is queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function executeClusterTask(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->clusterResource = $workload;

        return $this->executeTask($request, $workload);
    }
}
