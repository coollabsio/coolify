<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListDestinations extends Tool
{
    protected string $name = 'list_destinations';

    protected string $description = 'List Docker destinations for the authenticated team. Optional server_uuid filter.';

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

        $serverUuid = $request->get('server_uuid');
        $serverId = null;
        if ($serverUuid !== null) {
            if (! is_string($serverUuid) || $serverUuid === '') {
                return $this->mcpError($request, 'server_uuid must be a non-empty string.');
            }
            $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
            if (! $server) {
                return $this->mcpError($request, "Server [{$serverUuid}] not found.", ['resource_uuid' => $serverUuid]);
            }
            $serverId = $server->id;
        }

        $standaloneQuery = StandaloneDocker::with('server:id,uuid')
            ->whereHas('server', fn ($q) => $q->whereTeamId($teamId));
        $swarmQuery = SwarmDocker::with('server:id,uuid')
            ->whereHas('server', fn ($q) => $q->whereTeamId($teamId));

        if ($serverId !== null) {
            $standaloneQuery->where('server_id', $serverId);
            $swarmQuery->where('server_id', $serverId);
        }

        $destinations = $standaloneQuery->get()
            ->map(fn ($d) => $this->transform($d, 'standalone'))
            ->concat($swarmQuery->get()->map(fn ($d) => $this->transform($d, 'swarm')))
            ->values();

        $args = $this->paginationArgs($request);
        $total = $destinations->count();
        $page = $destinations->slice($args['offset'], $args['per_page'])->values()->all();

        $extra = array_filter(['server_uuid' => is_string($serverUuid) ? $serverUuid : null]);

        return $this->mcpSuccess($request, $this->respond(
            $page,
            [],
            $this->paginationMeta('list_destinations', $args, $total, $extra),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(StandaloneDocker|SwarmDocker $destination, string $type): array
    {
        return [
            'uuid' => $destination->uuid,
            'name' => $destination->name,
            'network' => $destination->network,
            'type' => $type,
            'server_uuid' => $destination->server?->uuid,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_uuid' => $schema->string()->description('Optional server UUID filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
