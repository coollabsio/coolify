<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetDestination extends Tool
{
    protected string $name = 'get_destination';

    protected string $description = 'Get a Docker destination by UUID for the authenticated team.';

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

        $destination = StandaloneDocker::with('server:id,uuid')
            ->whereHas('server', fn ($q) => $q->whereTeamId($teamId))
            ->whereUuid($uuid)
            ->first()
            ?? SwarmDocker::with('server:id,uuid')
                ->whereHas('server', fn ($q) => $q->whereTeamId($teamId))
                ->whereUuid($uuid)
                ->first();

        if (! $destination) {
            return $this->mcpError($request, "Destination [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $type = $destination instanceof SwarmDocker ? 'swarm' : 'standalone';

        return $this->mcpSuccess($request, $this->respond([
            'uuid' => $destination->uuid,
            'name' => $destination->name,
            'network' => $destination->network,
            'type' => $type,
            'server_uuid' => $destination->server?->uuid,
            'created_at' => $destination->created_at,
            'updated_at' => $destination->updated_at,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Destination UUID.')->required(),
        ];
    }
}
