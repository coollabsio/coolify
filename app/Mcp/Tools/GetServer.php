<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetServer extends Tool
{
    protected string $name = 'get_server';

    protected string $description = 'Get full details for a single server by UUID.';

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

        $server = Server::whereTeamId($teamId)->where('uuid', $uuid)->with('settings')->first();
        if (! $server) {
            return $this->mcpError($request, "Server [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $data = $this->scrubSensitive($server->toArray());
        $data['is_reachable'] = $server->settings?->is_reachable;
        $data['is_usable'] = $server->settings?->is_usable;
        $data['connection_timeout'] = $server->settings?->connection_timeout;

        return $this->mcpSuccess($request, $this->respond($data, $this->actionsForServer($uuid)), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Server UUID.')->required(),
        ];
    }
}
