<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ScheduledTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListScheduledTaskExecutions extends Tool
{
    protected string $name = 'list_scheduled_task_executions';

    protected string $description = 'List recent executions for a scheduled task on an application or service owned by the authenticated team. Execution messages require read:sensitive and are best-effort redacted.';

    use BuildsResponse;
    use ResolvesResource;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');
        $taskUuid = $request->get('task_uuid');

        if (! is_string($resourceType) || ! $this->isValidResourceType($resourceType, $this->scheduledTaskResourceTypes)) {
            return $this->mcpError($request, 'resource must be one of: application, service.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }
        if (! is_string($taskUuid) || $taskUuid === '') {
            return $this->mcpError($request, 'task_uuid argument is required.');
        }

        $resource = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $resource) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $taskQuery = ScheduledTask::ownedByCurrentTeamAPI($teamId)->where('uuid', $taskUuid);
        if ($resourceType === 'application') {
            $taskQuery->where('application_id', $resource->id);
        } else {
            $taskQuery->where('service_id', $resource->id);
        }

        $task = $taskQuery->first();
        if (! $task) {
            return $this->mcpError($request, "Scheduled task [{$taskUuid}] not found.", ['resource_uuid' => $taskUuid]);
        }

        // Free-form execution output can embed secrets; gate like get_logs / task commands.
        $token = $request->user()?->currentAccessToken();
        $includeMessage = $token !== null && ($token->can('root') || $token->can('read:sensitive'));

        $args = $this->paginationArgs($request);
        $execQuery = $task->executions()->orderByDesc('created_at')->orderByDesc('id');
        $total = (clone $execQuery)->count();
        $executions = $execQuery
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(function ($ex) use ($includeMessage) {
                $row = [
                    'uuid' => $ex->uuid ?? null,
                    'status' => $ex->status ?? null,
                    'message_included' => $includeMessage,
                    'created_at' => $ex->created_at,
                    'updated_at' => $ex->updated_at,
                ];
                if ($includeMessage) {
                    $message = $ex->message;
                    $row['message'] = is_string($message) ? $this->redactLogText($message) : $message;
                }

                return $this->scrubSensitive($row);
            })
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            [
                'resource' => $resourceType,
                'uuid' => $uuid,
                'task_uuid' => $taskUuid,
                'message_included' => $includeMessage,
                'executions' => $executions,
            ],
            [],
            $this->paginationMeta('list_scheduled_task_executions', $args, $total, [
                'resource' => $resourceType,
                'uuid' => $uuid,
                'task_uuid' => $taskUuid,
            ]),
        ), ['resource_uuid' => $taskUuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | service')->required(),
            'uuid' => $schema->string()->description('Parent resource UUID.')->required(),
            'task_uuid' => $schema->string()->description('Scheduled task UUID.')->required(),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
