<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetServerResources extends Tool
{
    protected string $name = 'get_server_resources';

    protected string $description = 'List resources defined on a server owned by the authenticated team.';

    use BuildsResponse;
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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return $this->mcpError($request, "Server [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $resources = $server->definedResources()->map(fn ($resource) => $this->scrubSensitive([
            'uuid' => $resource->uuid,
            'name' => $resource->name,
            'type' => method_exists($resource, 'type') ? $resource->type() : class_basename($resource),
            'status' => $resource->status ?? null,
            'created_at' => $resource->created_at,
            'updated_at' => $resource->updated_at,
        ]))->values()->all();

        return $this->mcpSuccess($request, $this->respond([
            'server_uuid' => $server->uuid,
            'resources' => $resources,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Server UUID.')->required(),
        ];
    }
}
