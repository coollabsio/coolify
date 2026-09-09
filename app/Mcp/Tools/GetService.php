<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetService extends Tool
{
    protected string $name = 'get_service';

    protected string $description = 'Get full details for a single service (multi-container stack) by UUID.';

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

        $service->setRelations([]);
        $service->makeHidden(['destination', 'source', 'environment', 'applications', 'databases', 'serviceApplications', 'serviceDatabases']);

        return $this->mcpSuccess($request, $this->respond(
            $this->scrubSensitive($service->toArray()),
            $this->actionsForService($uuid, $service->status ?? null),
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Service UUID.')->required(),
        ];
    }
}
