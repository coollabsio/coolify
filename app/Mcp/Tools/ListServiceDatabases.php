<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServiceDatabases extends Tool
{
    protected string $name = 'list_service_databases';

    protected string $description = 'List database components of a service stack owned by the authenticated team.';

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

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return $this->mcpError($request, "Service [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $databases = $service->databases()
            ->get()
            ->map(fn ($db) => $this->scrubSensitive([
                'uuid' => $db->uuid,
                'name' => $db->name,
                'human_name' => $db->human_name ?? null,
                'status' => $db->status,
                'image' => $db->image ?? null,
            ]))
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond([
            'service_uuid' => $uuid,
            'databases' => $databases,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Service UUID.')->required(),
        ];
    }
}
