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

class ListScheduledTasks extends Tool
{
    protected string $name = 'list_scheduled_tasks';

    protected string $description = 'List scheduled tasks for an application or service owned by the authenticated team. Task command bodies require read:sensitive; without it only metadata is returned.';

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

        if (! is_string($resourceType) || ! $this->isValidResourceType($resourceType, $this->scheduledTaskResourceTypes)) {
            return $this->mcpError($request, 'resource must be one of: application, service.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $resource = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $resource) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        // Optional: command bodies are sensitive; omit unless token has read:sensitive/root.
        // Do not call ensureAbility() here — lack of sensitive ability must not fail the tool.
        $token = $request->user()?->currentAccessToken();
        $includeCommand = $token !== null && ($token->can('root') || $token->can('read:sensitive'));

        $query = ScheduledTask::ownedByCurrentTeamAPI($teamId);
        if ($resourceType === 'application') {
            $query->where('application_id', $resource->id);
        } else {
            $query->where('service_id', $resource->id);
        }

        $tasks = $query->get()->map(function ($task) use ($includeCommand) {
            $row = [
                'uuid' => $task->uuid,
                'name' => $task->name,
                'enabled' => $task->enabled,
                'frequency' => $task->frequency,
                'container' => $task->container,
                'timeout' => $task->timeout,
                'command_included' => $includeCommand,
            ];
            if ($includeCommand) {
                $row['command'] = $task->command;
            }

            return $this->scrubSensitive($row);
        })->values()->all();

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $uuid,
            'tasks' => $tasks,
            'command_included' => $includeCommand,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | service')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
        ];
    }
}
