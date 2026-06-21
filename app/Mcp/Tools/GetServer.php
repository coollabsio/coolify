<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_server')]
#[Description('Get full details for a single server by UUID.')]
class GetServer extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $server = Server::whereTeamId($teamId)->where('uuid', $uuid)->with('settings')->first();
        if (! $server) {
            return Response::error("Server [{$uuid}] not found.");
        }

        $data = $this->scrubSensitive($server->toArray());
        $data['is_reachable'] = $server->settings?->is_reachable;
        $data['is_usable'] = $server->settings?->is_usable;
        $data['connection_timeout'] = $server->settings?->connection_timeout;

        return $this->respond($data, $this->actionsForServer($uuid));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Server UUID.')->required(),
        ];
    }
}
