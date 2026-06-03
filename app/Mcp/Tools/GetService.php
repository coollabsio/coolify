<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_service')]
#[Description('Get full details for a single service (multi-container stack) by UUID.')]
class GetService extends Tool
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

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return Response::error("Service [{$uuid}] not found.");
        }

        $service->setRelations([]);
        $service->makeHidden(['destination', 'source', 'environment', 'applications', 'databases', 'serviceApplications', 'serviceDatabases']);

        return $this->respond(
            $this->scrubSensitive($service->toArray()),
            $this->actionsForService($uuid, $service->status ?? null),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Service UUID.')->required(),
        ];
    }
}
